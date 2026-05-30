<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\NotificationKind;
use App\Mail\SourceHealthMail;
use App\Models\NotificationLogEntry;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\SettingsRepository;
use App\Sources\SourceHealthQuery;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Database\Eloquent\Collection;

/**
 * Sweeps aggregator source health and emails a fix-me alert for any source
 * that is RED — either failing its configured number of consecutive scheduled
 * runs or showing a sharp week-over-week parse-confidence drop (see
 * {@see SourceHealthQuery}).
 *
 * Deduped via the notification_log ledger: a NotificationLogEntry with
 * kind=SOURCE_HEALTH, company_id=NULL, and context.source_key carrying the
 * source identifier records each alert. A source only alerts again once it has
 * recovered out of RED and back in — i.e. while a source stays red across runs
 * it does not re-alert, because the most recent SOURCE_HEALTH log row for that
 * source key is still an alert rather than a recovery.
 *
 * Runs per tenant: --tenant=<slug> targets one tenant; omitting it sweeps every
 * tenant. The Mailable implements ShouldQueue, so the queue worker performs the
 * actual send via the MAIL_MAILER-configured transport.
 */
final class MonitorSourceHealthCommand extends Command
{
    protected $signature = 'monitor:source-health
        {--tenant= : Limit the sweep to a single tenant slug. Omit to sweep all tenants.}';

    protected $description = 'Detect unhealthy aggregator sources and email a deduped fix-me alert for each one that is red.';

    public function __construct(
        private readonly SourceHealthQuery $sourceHealth,
        private readonly SettingsRepository $settings,
        private readonly Mailer $mailer,
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
        $totalAlerts = 0;

        foreach ($tenants as $tenant) {
            $totalAlerts += $context->withTenant(
                $tenant,
                fn (): int => $this->sweepTenant($now),
            );
        }

        $this->line((string) json_encode([
            'command' => 'monitor:source-health',
            'tenants_swept' => $tenants->count(),
            'alerts_queued' => $totalAlerts,
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /**
     * Alert on every red source in the active tenant that is not already in an
     * un-recovered alert state, recording a notification_log row per send.
     */
    private function sweepTenant(CarbonImmutable $now): int
    {
        $recipient = $this->recipient();
        $queued = 0;

        foreach ($this->sourceHealth->perSource($now) as $source) {
            if ($source['status'] !== 'red') {
                continue;
            }

            if ($this->alreadyAlerted($source['key'])) {
                continue;
            }

            if (is_string($recipient) && $recipient !== '') {
                $this->mailer
                    ->to($recipient)
                    ->queue(new SourceHealthMail(
                        sourceName: $source['name'],
                        sourceKey: $source['key'],
                        reasons: $source['reasons'],
                        consecutiveFailures: $source['consecutive_failures'],
                        lastError: $source['last_error'],
                        lastRunAt: $source['last_run_at'],
                    ));

                $queued++;
            }

            $this->recordAlert($source, $recipient, $now);
        }

        return $queued;
    }

    /**
     * A source is "already alerted" when its most recent SOURCE_HEALTH log row
     * is an alert (context.recovered is not true). Once a recovery row is
     * written — when the source drops out of red — the next red episode alerts
     * again.
     */
    private function alreadyAlerted(string $sourceKey): bool
    {
        $latest = NotificationLogEntry::query()
            ->where('kind', NotificationKind::SOURCE_HEALTH)
            ->whereNull('company_id')
            ->where('context->source_key', $sourceKey)
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->first();

        if ($latest === null) {
            return false;
        }

        $context = $latest->context ?? [];

        return ! (is_array($context) && ($context['recovered'] ?? false) === true);
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function recordAlert(array $source, ?string $recipient, CarbonImmutable $now): void
    {
        $entry = new NotificationLogEntry;
        $entry->setAttribute('kind', NotificationKind::SOURCE_HEALTH);
        $entry->setAttribute('company_id', null);
        $entry->setAttribute('recipient', is_string($recipient) && $recipient !== '' ? $recipient : 'unset');
        $entry->setAttribute('subject', 'Source health: '.$source['name'].' needs attention');
        $entry->setAttribute('context', [
            'source_key' => $source['key'],
            'source_id' => $source['source_id'],
            'reasons' => $source['reasons'],
            'consecutive_failures' => $source['consecutive_failures'],
            'recovered' => false,
        ]);
        $entry->setAttribute('sent_at', $now);
        $entry->save();
    }

    /**
     * Notification recipient for the active tenant: the notification_email
     * Setting, falling back to the first user's email.
     */
    private function recipient(): ?string
    {
        $configured = $this->settings->get(SettingKey::NOTIFICATION_EMAIL);

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $user = User::query()->orderBy('id')->first();

        return is_string($user?->email) ? $user->email : null;
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
