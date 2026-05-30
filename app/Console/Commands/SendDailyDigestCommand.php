<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\NotificationKind;
use App\Mail\DailyDigestMail;
use App\Models\NotificationLogEntry;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\DailyDigest;
use App\Notifications\DailyDigestQuery;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Mail\Mailer;

/**
 * Queues a per-tenant daily digest at the recipient's configured local send
 * time.
 *
 * Scheduling approach (documented per slice brief):
 * The scheduler invokes this command HOURLY. Rather than precomputing a single
 * UTC fire time (which DST shifts would silently desync), each run gates every
 * tenant on the recipient's *local* wall-clock hour: a tenant is sent only when
 * its recipient's current local time falls in the configured send hour
 * (`notifications.digest_send_time`, e.g. "07:00", overridable per-tenant via
 * the `digest_send_time` Setting). The recipient timezone is User.timezone,
 * falling back to `notifications.timezone`. This keeps "7am in the user's
 * timezone" correct across offsets and DST without code changes, and an
 * `--hour` override exists for tests and manual re-fires.
 *
 * Idempotency / dedupe:
 * At most one digest is queued per tenant per local calendar day. Before
 * sending, the command checks notification_log for a DAILY_DIGEST row recorded
 * since the recipient's local midnight; if present, the tenant is skipped. The
 * log row is written immediately after queueing, so an overlapping or repeated
 * hourly tick never double-sends.
 *
 * Skip-empty:
 * When the assembled digest has nothing to report and the `digest_skip_empty`
 * Setting (DB override of `notifications.digest_skip_empty`) is true, the send
 * is suppressed and no log row is written, so the next non-empty day still
 * sends.
 *
 * Runs per tenant: --tenant=<slug> targets one tenant; omitting it sweeps every
 * tenant. The Mailable implements ShouldQueue, so the queue worker performs the
 * actual send via the MAIL_MAILER-configured transport.
 */
final class SendDailyDigestCommand extends Command
{
    protected $signature = 'digest:send-daily
        {--tenant= : Limit the sweep to a single tenant slug. Omit to sweep all tenants.}
        {--hour= : Override the recipient local hour gate (0-23). For tests and manual re-fires; bypasses the configured send time.}';

    protected $description = 'Queue the daily digest for tenants whose recipient local time matches the configured send hour, deduped one per local day.';

    public function __construct(
        private readonly DailyDigestQuery $digestQuery,
        private readonly Mailer $mailer,
        private readonly ConfigRepository $config,
    ) {
        parent::__construct();
    }

    public function handle(TenantContext $context): int
    {
        $tenants = $this->resolveTenants();
        if ($tenants === null) {
            return self::FAILURE;
        }

        $now = CarbonImmutable::now();
        $hourOverride = $this->hourOverride();
        if ($hourOverride === false) {
            return self::FAILURE;
        }

        $totalQueued = 0;
        $skippedEmpty = 0;
        $skippedDuplicate = 0;

        foreach ($tenants as $tenant) {
            $result = $context->withTenant(
                $tenant,
                fn (): array => $this->processTenant($now, $hourOverride),
            );

            $totalQueued += $result['queued'];
            $skippedEmpty += $result['skipped_empty'];
            $skippedDuplicate += $result['skipped_duplicate'];
        }

        $this->line((string) json_encode([
            'command' => 'digest:send-daily',
            'tenants_swept' => $tenants->count(),
            'digests_queued' => $totalQueued,
            'skipped_empty' => $skippedEmpty,
            'skipped_duplicate' => $skippedDuplicate,
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /**
     * Evaluate a single tenant: resolve the recipient, gate on the local send
     * hour and the per-day dedupe, build the digest, honor skip-empty, then
     * queue and log.
     *
     * @param int|null $hourOverride explicit hour gate, or null to use config
     *
     * @return array{queued: int, skipped_empty: int, skipped_duplicate: int}
     */
    private function processTenant(CarbonImmutable $now, ?int $hourOverride): array
    {
        $idle = ['queued' => 0, 'skipped_empty' => 0, 'skipped_duplicate' => 0];

        $recipient = $this->recipient();
        if ($recipient === null) {
            return $idle;
        }

        $timezone = $this->timezoneFor($recipient);
        $localNow = $now->setTimezone($timezone);

        if (! $this->matchesSendHour($localNow, $hourOverride)) {
            return $idle;
        }

        if ($this->alreadySentToday($localNow)) {
            return ['queued' => 0, 'skipped_empty' => 0, 'skipped_duplicate' => 1];
        }

        $digest = $this->digestQuery->build($now);

        if ($digest->isEmpty() && $this->skipEmpty()) {
            return ['queued' => 0, 'skipped_empty' => 1, 'skipped_duplicate' => 0];
        }

        $this->mailer
            ->to($recipient)
            ->queue(new DailyDigestMail($digest, $timezone));

        $this->logSent($recipient, $now, $digest);

        return ['queued' => 1, 'skipped_empty' => 0, 'skipped_duplicate' => 0];
    }

    /**
     * The configured local hour gate matches the recipient's current local
     * hour. An explicit --hour override wins over the configured send time.
     */
    private function matchesSendHour(CarbonImmutable $localNow, ?int $hourOverride): bool
    {
        if ($hourOverride !== null) {
            return $localNow->hour === $hourOverride;
        }

        return $localNow->hour === $this->configuredSendHour();
    }

    /**
     * Whether a DAILY_DIGEST has already been logged for the active tenant
     * since the recipient's local midnight today.
     */
    private function alreadySentToday(CarbonImmutable $localNow): bool
    {
        $localMidnightUtc = $localNow->startOfDay()->utc();

        return NotificationLogEntry::query()
            ->where('kind', NotificationKind::DAILY_DIGEST->value)
            ->where('sent_at', '>=', $localMidnightUtc)
            ->exists();
    }

    private function logSent(string $recipient, CarbonImmutable $now, DailyDigest $digest): void
    {
        NotificationLogEntry::query()->create([
            'kind' => NotificationKind::DAILY_DIGEST,
            'company_id' => null,
            'recipient' => $recipient,
            'subject' => "Daily digest: {$digest->eventCount()} updates, {$digest->discoverQueueCount} to triage",
            'context' => [
                'event_count' => $digest->eventCount(),
                'discover_queue' => $digest->discoverQueueCount,
                'follow_up_queue' => $digest->followUpQueueCount,
                'stale_a_tier' => $digest->staleATierCompanies->count(),
            ],
            'sent_at' => $now,
        ]);
    }

    /**
     * Recipient address for the active tenant: the `notification_email`
     * Setting if present, else the first user's login email. Null when neither
     * resolves to a non-empty address.
     */
    private function recipient(): ?string
    {
        $setting = $this->settingValue('notification_email');
        if (is_string($setting) && $setting !== '') {
            return $setting;
        }

        $email = User::query()->orderBy('id')->value('email');

        return is_string($email) && $email !== '' ? $email : null;
    }

    /**
     * Timezone used for the local send-hour gate and digest rendering:
     * User.timezone, falling back to the configured notifications timezone.
     */
    private function timezoneFor(string $recipient): string
    {
        $userTimezone = User::query()
            ->where('email', $recipient)
            ->value('timezone');

        if (! is_string($userTimezone) || $userTimezone === '') {
            $userTimezone = User::query()->orderBy('id')->value('timezone');
        }

        if (is_string($userTimezone) && $userTimezone !== '') {
            return $userTimezone;
        }

        return (string) $this->config->get('notifications.timezone', 'UTC');
    }

    /**
     * Configured send hour for the active tenant: the `digest_send_time`
     * Setting (HH:MM) overriding the config default. Only the hour component
     * gates the hourly schedule.
     */
    private function configuredSendHour(): int
    {
        $setting = $this->settingValue('digest_send_time');
        $time = is_string($setting) && $setting !== ''
            ? $setting
            : (string) $this->config->get('notifications.digest_send_time', '07:00');

        return (int) explode(':', $time)[0];
    }

    /**
     * Skip-empty preference for the active tenant: the `digest_skip_empty`
     * Setting overriding the config default.
     */
    private function skipEmpty(): bool
    {
        $setting = $this->settingValue('digest_skip_empty');
        if (is_bool($setting)) {
            return $setting;
        }

        return (bool) $this->config->get('notifications.digest_skip_empty', true);
    }

    /**
     * Read a tenant-scoped Setting value (the active tenant scope bounds the
     * query). Returns null when the row is absent so callers fall back to
     * config defaults.
     */
    private function settingValue(string $key): mixed
    {
        return Setting::query()->where('key', $key)->value('value');
    }

    /**
     * Parse the --hour option. Returns null when unset (use config), an int
     * when valid, or false when the supplied value is out of range.
     */
    private function hourOverride(): int|false|null
    {
        $option = $this->option('hour');

        if ($option === null || $option === '') {
            return null;
        }

        if (! is_numeric($option)) {
            $this->error('The --hour option must be an integer between 0 and 23.');

            return false;
        }

        $hour = (int) $option;

        if ($hour < 0 || $hour > 23) {
            $this->error('The --hour option must be between 0 and 23.');

            return false;
        }

        return $hour;
    }

    /**
     * Resolve the set of tenants to sweep. Returns null (and prints an error)
     * when an explicit --tenant slug does not match a tenant.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Tenant>|null
     */
    private function resolveTenants(): ?\Illuminate\Database\Eloquent\Collection
    {
        $slug = $this->option('tenant');

        if (is_string($slug) && $slug !== '') {
            /** @var Tenant|null $tenant */
            $tenant = Tenant::query()->where('slug', $slug)->first();

            if (! $tenant instanceof Tenant) {
                $this->error("Tenant [{$slug}] not found.");

                return null;
            }

            return new \Illuminate\Database\Eloquent\Collection([$tenant]);
        }

        return Tenant::query()->orderBy('id')->get();
    }
}
