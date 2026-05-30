<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Calendar\DueRemindersQuery;
use App\Mail\CalendarEventReminderMail;
use App\Models\CalendarEvent;
use App\Models\User;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Database\Eloquent\Collection;

/**
 * Sweeps calendar_events for reminders that are due but not yet sent, queues a
 * reminder Mailable to each user in the owning tenant, and stamps
 * reminder_sent_at in the same pass.
 *
 * Idempotent by design: reminder_sent_at is stamped before the next event is
 * considered, so a re-run (or an overlapping tick) never double-sends. Because
 * the due-window predicate is "now is past the lead-time and the event is still
 * in the future", a missed scheduler tick simply means the reminder fires a
 * little late on the following run rather than being lost — it survives dyno
 * restarts and gaps in the minute schedule.
 *
 * Runs per tenant: --tenant=<slug> targets one tenant; omitting it sweeps every
 * tenant. The Mailable implements ShouldQueue, so the queue worker does the
 * actual send via the MAIL_MAILER-configured transport.
 */
final class SendCalendarRemindersCommand extends Command
{
    protected $signature = 'calendar:send-reminders
        {--tenant= : Limit the sweep to a single tenant slug. Omit to sweep all tenants.}';

    protected $description = 'Queue reminder emails for calendar events whose reminder lead-time has elapsed, then stamp them sent.';

    public function __construct(
        private readonly DueRemindersQuery $dueReminders,
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
        $totalQueued = 0;

        foreach ($tenants as $tenant) {
            $totalQueued += $context->withTenant(
                $tenant,
                fn (): int => $this->sweepTenant($now),
            );
        }

        $this->line((string) json_encode([
            'command' => 'calendar:send-reminders',
            'tenants_swept' => $tenants->count(),
            'reminders_queued' => $totalQueued,
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /**
     * Queue a reminder for every due event in the active tenant and stamp each
     * one sent before moving on, so an interrupted run never re-sends.
     */
    private function sweepTenant(CarbonImmutable $now): int
    {
        $recipients = $this->recipientsFor();

        if ($recipients->isEmpty()) {
            // No user to notify, but still stamp due events so they don't pile
            // up and re-evaluate forever once a user is added.
            foreach ($this->dueReminders->get($now) as $event) {
                $this->stampSent($event, $now);
            }

            return 0;
        }

        $queued = 0;

        foreach ($this->dueReminders->get($now) as $event) {
            foreach ($recipients as $recipient) {
                if (! is_string($recipient->email) || $recipient->email === '') {
                    continue;
                }

                $this->mailer
                    ->to($recipient->email)
                    ->queue(new CalendarEventReminderMail($event, $recipient));

                $queued++;
            }

            $this->stampSent($event, $now);
        }

        return $queued;
    }

    private function stampSent(CalendarEvent $event, CarbonImmutable $now): void
    {
        $event->reminder_sent_at = $now;
        $event->save();
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
