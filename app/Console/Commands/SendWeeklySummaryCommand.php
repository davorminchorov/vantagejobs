<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\NotificationKind;
use App\Mail\WeeklySummaryMail;
use App\Models\NotificationLogEntry;
use App\Models\User;
use App\Notifications\WeeklySummaryQuery;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Database\Eloquent\Collection;

/**
 * Sends the Sunday-morning weekly rollup: 7-day counts of new roles, companies
 * added, applications sent, and follow-ups due; the week's top signals; and the
 * pipeline-stage trend versus the prior week.
 *
 * Gated to one WEEKLY_SUMMARY per tenant per (ISO) week via a notification_log
 * lookup, so a re-run or an overlapping scheduler tick never double-sends. When
 * config('notifications.digest_skip_empty') is true (the default) and nothing
 * happened in the window, the send is suppressed and no log row is written, so
 * a later run that same week can still fire once real activity exists.
 *
 * Runs per tenant: --tenant=<slug> targets one tenant; omitting it sweeps every
 * tenant. The Mailable implements ShouldQueue, so the queue worker does the
 * actual send via the MAIL_MAILER-configured transport.
 */
final class SendWeeklySummaryCommand extends Command
{
    protected $signature = 'notifications:send-weekly-summary
        {--tenant= : Limit the run to a single tenant slug. Omit to sweep all tenants.}';

    protected $description = 'Queue the Sunday-morning weekly summary email, once per tenant per week.';

    public function __construct(
        private readonly WeeklySummaryQuery $summaryQuery,
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
        $totalQueued = 0;

        foreach ($tenants as $tenant) {
            $totalQueued += $context->withTenant(
                $tenant,
                fn (): int => $this->sweepTenant($now),
            );
        }

        $this->line((string) json_encode([
            'command' => 'notifications:send-weekly-summary',
            'tenants_swept' => $tenants->count(),
            'summaries_queued' => $totalQueued,
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /**
     * Queue the weekly summary for the active tenant, gated to once per week and
     * suppressed when skip-empty is on and nothing happened.
     */
    private function sweepTenant(CarbonImmutable $now): int
    {
        if ($this->alreadySentThisWeek($now)) {
            return 0;
        }

        $recipients = $this->recipientsFor();
        if ($recipients->isEmpty()) {
            return 0;
        }

        $summary = $this->summaryQuery->forWeekEnding($now);

        if ($this->shouldSkipEmpty() && $summary['has_activity'] === false) {
            return 0;
        }

        $queued = 0;

        foreach ($recipients as $recipient) {
            $email = $this->recipientEmail($recipient);
            if ($email === null) {
                continue;
            }

            $this->mailer
                ->to($email)
                ->queue(new WeeklySummaryMail($recipient, $summary));

            $this->recordSent($email, $summary, $now);

            $queued++;
        }

        return $queued;
    }

    /**
     * True when a WEEKLY_SUMMARY row already exists for the active tenant inside
     * the ISO week containing $now. The global tenant scope bounds the lookup.
     */
    private function alreadySentThisWeek(CarbonImmutable $now): bool
    {
        $weekStart = $now->startOfWeek();
        $weekEnd = $now->endOfWeek();

        return NotificationLogEntry::query()
            ->where('kind', NotificationKind::WEEKLY_SUMMARY->value)
            ->whereBetween('sent_at', [$weekStart, $weekEnd])
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function recordSent(string $recipient, array $summary, CarbonImmutable $now): void
    {
        NotificationLogEntry::query()->create([
            'kind' => NotificationKind::WEEKLY_SUMMARY,
            'company_id' => null,
            'recipient' => $recipient,
            'subject' => 'Your week in review · '.CarbonImmutable::parse($summary['window_end'])->format('M j'),
            'context' => [
                'new_roles' => $summary['new_roles'],
                'companies_added' => $summary['companies_added'],
                'applications_sent' => $summary['applications_sent'],
                'offers_recorded' => $summary['offers_recorded'],
                'follow_ups_due' => $summary['follow_ups_due'],
                'top_signals' => count($summary['top_signals']),
            ],
            'sent_at' => $now,
        ]);
    }

    private function shouldSkipEmpty(): bool
    {
        return (bool) $this->config->get('notifications.digest_skip_empty', true);
    }

    private function recipientEmail(User $user): ?string
    {
        $email = $user->getAttribute('email');

        if (is_string($email) && $email !== '') {
            return $email;
        }

        return null;
    }

    /**
     * Users to notify within the active tenant. The global tenant scope bounds
     * the query, so this returns only the current tenant's users.
     *
     * @return Collection<int, User>
     */
    private function recipientsFor(): Collection
    {
        return User::query()->orderBy('id')->get();
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
