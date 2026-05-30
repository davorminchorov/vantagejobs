<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\NotificationKind;
use App\Mail\ATierAlertMail;
use App\Models\Company;
use App\Models\NotificationLogEntry;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\ATierAlertsQuery;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Config\Repository as Config;
use Illuminate\Console\Command;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Database\Eloquent\Collection;

/**
 * Sweeps for A-tier companies that have posted new open roles and emails an
 * instant alert, at most once per company per throttle window, deferring while
 * the recipient is inside their do-not-disturb window.
 *
 * Throttle: a company is skipped if an A_TIER_ALERT notification_log row exists
 * for it within the last `notifications.a_tier_throttle_minutes` (default 60).
 * Once an alert is queued, recordSent appends the ledger row, so the same
 * company will not be re-alerted until the window elapses — the sweep is
 * idempotent across overlapping or re-run ticks.
 *
 * Lookback: roles created within the last `lookback` minutes (default 15, the
 * sweep cadence) are considered new. A missed tick simply means a slightly
 * later alert on the following run rather than a lost one, as long as the
 * lookback is at least the cadence; pass --lookback to widen it after an outage.
 *
 * Do-not-disturb: when the recipient's local wall-clock falls inside the DND
 * window the sweep defers (does not record, does not send) so the same roles
 * re-evaluate and fire on the next allowed sweep, never dropping the alert.
 *
 * Runs per tenant: --tenant=<slug> targets one tenant; omitting it sweeps every
 * tenant. The Mailable implements ShouldQueue, so the queue worker performs the
 * actual send via the MAIL_MAILER-configured transport.
 */
final class SendATierAlertsCommand extends Command
{
    protected $signature = 'notifications:send-a-tier-alerts
        {--tenant= : Limit the sweep to a single tenant slug. Omit to sweep all tenants.}
        {--lookback= : Minutes of role-creation history to treat as new. Defaults to the sweep cadence.}';

    protected $description = 'Queue instant A-tier alert emails for companies with newly posted open roles, throttled and DND-aware.';

    public function __construct(
        private readonly ATierAlertsQuery $alerts,
        private readonly Mailer $mailer,
        private readonly Config $config,
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
        $lookbackMinutes = $this->lookbackMinutes();
        $throttleMinutes = (int) $this->config->get('notifications.a_tier_throttle_minutes', 60);

        $totalQueued = 0;
        $totalDeferred = 0;

        foreach ($tenants as $tenant) {
            $result = $context->withTenant(
                $tenant,
                fn (): array => $this->sweepTenant($now, $lookbackMinutes, $throttleMinutes),
            );

            $totalQueued += $result['queued'];
            $totalDeferred += $result['deferred'];
        }

        $this->line((string) json_encode([
            'command' => 'notifications:send-a-tier-alerts',
            'tenants_swept' => $tenants->count(),
            'alerts_queued' => $totalQueued,
            'companies_deferred' => $totalDeferred,
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /**
     * Queue one alert per eligible company in the active tenant and record each
     * send in the ledger before moving on, so an interrupted run never
     * re-alerts a company within the throttle window.
     *
     * @return array{queued: int, deferred: int}
     */
    private function sweepTenant(
        CarbonImmutable $now,
        int $lookbackMinutes,
        int $throttleMinutes,
    ): array {
        $recipient = $this->recipient();

        if ($recipient === null) {
            // No deliverable recipient: skip without recording, so alerts fire
            // once a notification address exists.
            return ['queued' => 0, 'deferred' => 0];
        }

        // DND defers the whole sweep for this tenant: leave the eligible roles
        // untouched so they re-evaluate and fire on the next allowed sweep.
        if ($this->isWithinDoNotDisturb($now)) {
            $deferred = $this->alerts->get($now, $lookbackMinutes, $throttleMinutes)->count();

            return ['queued' => 0, 'deferred' => $deferred];
        }

        $queued = 0;

        foreach ($this->alerts->get($now, $lookbackMinutes, $throttleMinutes) as $company) {
            /** @var Collection<int, \App\Models\Role> $roles */
            $roles = $company->getRelation('roles');

            if ($roles->isEmpty()) {
                continue;
            }

            $mail = new ATierAlertMail($company, $roles);

            $this->mailer->to($recipient)->queue($mail);
            $this->recordSent($company, $recipient, $now, $roles->count());

            $queued++;
        }

        return ['queued' => $queued, 'deferred' => 0];
    }

    /**
     * Append an A_TIER_ALERT row to the notification ledger. This both throttles
     * future sweeps (1 alert per company per window) and provides an audit trail
     * of what was emailed when.
     */
    private function recordSent(
        Company $company,
        string $recipient,
        CarbonImmutable $now,
        int $roleCount,
    ): void {
        NotificationLogEntry::query()->create([
            'kind' => NotificationKind::A_TIER_ALERT,
            'company_id' => $company->id,
            'recipient' => $recipient,
            'subject' => "A-tier alert: {$roleCount} new ".($roleCount === 1 ? 'role' : 'roles')." at {$company->name}",
            'context' => ['role_count' => $roleCount],
            'sent_at' => $now,
        ]);
    }

    /**
     * The notification recipient for the active tenant: the `notification_email`
     * Setting when present, otherwise the first user's login email. Returns null
     * when neither yields a deliverable address.
     */
    private function recipient(): ?string
    {
        /** @var Setting|null $row */
        $row = Setting::query()->where('key', 'notification_email')->first();
        $setting = $row?->value;

        if (is_string($setting) && $setting !== '') {
            return $setting;
        }

        if (is_array($setting) && isset($setting[0]) && is_string($setting[0]) && $setting[0] !== '') {
            return $setting[0];
        }

        /** @var User|null $user */
        $user = User::query()->orderBy('id')->first();

        if ($user !== null && is_string($user->email) && $user->email !== '') {
            return $user->email;
        }

        return null;
    }

    /**
     * Whether the current moment falls inside the recipient's do-not-disturb
     * window, evaluated in the recipient timezone. Handles windows that wrap
     * past midnight (start later than end).
     */
    private function isWithinDoNotDisturb(CarbonImmutable $now): bool
    {
        $start = (string) $this->config->get('notifications.dnd_start', '22:00');
        $end = (string) $this->config->get('notifications.dnd_end', '07:00');

        if ($start === $end) {
            return false;
        }

        $local = $now->setTimezone($this->recipientTimezone())->format('H:i');

        if ($start < $end) {
            return $local >= $start && $local < $end;
        }

        // Wrapping window, e.g. 22:00 -> 07:00.
        return $local >= $start || $local < $end;
    }

    private function recipientTimezone(): string
    {
        /** @var User|null $user */
        $user = User::query()->orderBy('id')->first();

        $userTimezone = $user?->getAttribute('timezone');

        if (is_string($userTimezone) && $userTimezone !== '') {
            return $userTimezone;
        }

        return (string) $this->config->get('notifications.timezone', 'UTC');
    }

    private function lookbackMinutes(): int
    {
        $option = $this->option('lookback');

        if (is_string($option) && $option !== '' && ctype_digit($option)) {
            return (int) $option;
        }

        return 15;
    }

    /**
     * Resolve the set of tenants to sweep. Returns null (and prints an error)
     * when an explicit --tenant slug does not match a tenant.
     *
     * @return Collection<int, Tenant>|null
     */
    private function resolveTenants(): ?Collection
    {
        $slug = $this->option('tenant');

        if (is_string($slug) && $slug !== '') {
            /** @var Tenant|null $tenant */
            $tenant = Tenant::query()->where('slug', $slug)->first();

            if (! $tenant instanceof Tenant) {
                $this->error("Tenant [{$slug}] not found.");

                return null;
            }

            return new Collection([$tenant]);
        }

        return Tenant::query()->orderBy('id')->get();
    }
}
