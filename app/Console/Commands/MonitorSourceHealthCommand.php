<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\NotificationKind;
use App\Mail\AtsHealthMail;
use App\Mail\SourceHealthMail;
use App\Models\Company;
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
 * Sweeps aggregator source health and ATS company health, emailing a fix-me
 * alert for anything RED — a source failing its configured number of
 * consecutive scheduled runs, a sharp week-over-week parse-confidence drop,
 * a company's most recent ATS scrape ending in `error`, or a company the
 * ATS-switch detector flagged as `redetect_failed` (see {@see SourceHealthQuery}).
 *
 * Both halves are deduped via the notification_log ledger, keyed differently
 * because a source has no row of its own and a company does:
 * - Sources: kind=SOURCE_HEALTH, company_id=NULL, context.source_key carries
 *   the source identifier.
 * - Companies: kind=ATS_HEALTH, company_id set, matching the existing
 *   A_TIER_ALERT dedup convention.
 * Either way, the subject only alerts again once it has recovered out of RED
 * and back in.
 *
 * That recovery half of the loop is written here too: when a source or
 * company that previously alerted (its latest log row has context.recovered
 * === false) is no longer RED, this sweep writes a recovery row
 * (context.recovered === true, no email). Without it, "already alerted" would
 * see that same un-recovered row forever and a subject that degrades,
 * recovers, then degrades again would only ever alert once in its lifetime.
 *
 * Runs per tenant: --tenant=<slug> targets one tenant; omitting it sweeps every
 * tenant. The Mailables implement ShouldQueue, so the queue worker performs the
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
     * Alert on every red source and every red ATS company in the active
     * tenant that is not already in an un-recovered alert state, recording a
     * notification_log row per send. Also re-arms any subject that has come
     * back out of red since its last alert by writing a recovery row.
     */
    private function sweepTenant(CarbonImmutable $now): int
    {
        $recipient = $this->recipient();
        $queued = 0;

        foreach ($this->sourceHealth->perSource($now) as $source) {
            if ($source['status'] !== 'red') {
                if ($this->alreadyAlerted($source['key'])) {
                    $this->recordRecovery($source, $now);
                }

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

        $queued += $this->sweepAtsCompanies($now, $recipient);

        return $queued;
    }

    /**
     * ATS companies never get a "healthy" row from perAtsCompany() — unlike
     * perSource(), which lists every enabled source including green ones —
     * so a company that fully recovers (back to `ok`, no recent failing run)
     * simply disappears from the row set instead of showing up as green.
     * Recovery therefore needs two passes: degraded rows still present (red
     * alerts, or a demotion to yellow counts as recovered-from-red) and
     * previously-alerted companies that vanished from the row set entirely.
     */
    private function sweepAtsCompanies(CarbonImmutable $now, ?string $recipient): int
    {
        $queued = 0;
        $rows = collect($this->sourceHealth->perAtsCompany($now))->keyBy('company_id');

        foreach ($rows as $companyId => $company) {
            if ($company['status'] !== 'red') {
                if ($this->alreadyAlertedForCompany($companyId)) {
                    $this->recordCompanyRecovery($companyId, $company['name'], $company['consecutive_failures'], $now);
                }

                continue;
            }

            if ($this->alreadyAlertedForCompany($companyId)) {
                continue;
            }

            if (is_string($recipient) && $recipient !== '') {
                $this->mailer
                    ->to($recipient)
                    ->queue(new AtsHealthMail(
                        companyId: $companyId,
                        companyName: $company['name'],
                        reasons: $company['reasons'],
                        consecutive404s: $company['consecutive_failures'],
                        lastRunAt: $company['last_run_at'],
                        companyUrl: route('companies.show', $companyId),
                    ));

                $queued++;
            }

            $this->recordCompanyAlert($company, $recipient, $now);
        }

        $recoveredCompanyIds = NotificationLogEntry::query()
            ->where('kind', NotificationKind::ATS_HEALTH)
            ->whereNotNull('company_id')
            ->distinct()
            ->pluck('company_id')
            ->diff($rows->keys())
            ->filter(fn (int $companyId): bool => $this->alreadyAlertedForCompany($companyId));

        if ($recoveredCompanyIds->isNotEmpty()) {
            $names = Company::query()->whereIn('id', $recoveredCompanyIds)->pluck('name', 'id');

            foreach ($recoveredCompanyIds as $companyId) {
                $name = (string) ($names->get($companyId) ?? ('Company #'.$companyId));
                $this->recordCompanyRecovery($companyId, $name, 0, $now);
            }
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
     * Log-only — no email. Marks the source's un-recovered alert as resolved
     * so alreadyAlerted() lets the next red episode alert again.
     *
     * @param  array<string, mixed>  $source
     */
    private function recordRecovery(array $source, CarbonImmutable $now): void
    {
        $entry = new NotificationLogEntry;
        $entry->setAttribute('kind', NotificationKind::SOURCE_HEALTH);
        $entry->setAttribute('company_id', null);
        $entry->setAttribute('recipient', 'unset');
        $entry->setAttribute('subject', 'Source health: '.$source['name'].' recovered');
        $entry->setAttribute('context', [
            'source_key' => $source['key'],
            'source_id' => $source['source_id'],
            'reasons' => [],
            'consecutive_failures' => $source['consecutive_failures'],
            'recovered' => true,
        ]);
        $entry->setAttribute('sent_at', $now);
        $entry->save();
    }

    /**
     * A company is "already alerted" when its most recent ATS_HEALTH log row
     * is an alert (context.recovered is not true). Once a recovery row is
     * written — when the company drops out of red — the next red episode
     * alerts again. Mirrors alreadyAlerted() for sources, keyed by
     * company_id instead of context.source_key.
     */
    private function alreadyAlertedForCompany(int $companyId): bool
    {
        $latest = NotificationLogEntry::query()
            ->where('kind', NotificationKind::ATS_HEALTH)
            ->where('company_id', $companyId)
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
     * @param  array<string, mixed>  $company
     */
    private function recordCompanyAlert(array $company, ?string $recipient, CarbonImmutable $now): void
    {
        $entry = new NotificationLogEntry;
        $entry->setAttribute('kind', NotificationKind::ATS_HEALTH);
        $entry->setAttribute('company_id', $company['company_id']);
        $entry->setAttribute('recipient', is_string($recipient) && $recipient !== '' ? $recipient : 'unset');
        $entry->setAttribute('subject', 'ATS health: '.$company['name'].' needs attention');
        $entry->setAttribute('context', [
            'reasons' => $company['reasons'],
            'consecutive_failures' => $company['consecutive_failures'],
            'recovered' => false,
        ]);
        $entry->setAttribute('sent_at', $now);
        $entry->save();
    }

    /**
     * Log-only — no email. Marks the company's un-recovered alert as resolved
     * so alreadyAlertedForCompany() lets the next red episode alert again.
     */
    private function recordCompanyRecovery(int $companyId, string $companyName, int $consecutiveFailures, CarbonImmutable $now): void
    {
        $entry = new NotificationLogEntry;
        $entry->setAttribute('kind', NotificationKind::ATS_HEALTH);
        $entry->setAttribute('company_id', $companyId);
        $entry->setAttribute('recipient', 'unset');
        $entry->setAttribute('subject', 'ATS health: '.$companyName.' recovered');
        $entry->setAttribute('context', [
            'reasons' => [],
            'consecutive_failures' => $consecutiveFailures,
            'recovered' => true,
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
