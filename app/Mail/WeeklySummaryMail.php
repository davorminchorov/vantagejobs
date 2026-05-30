<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Queued Sunday-morning weekly rollup. Dispatched by the
 * notifications:send-weekly-summary sweep once per tenant per week.
 *
 * Provider is config-driven via MAIL_MAILER — no provider SDK is hardcoded.
 * The summary payload is computed up front (the query needs an active tenant
 * context the queue worker does not have), so the Mailable carries a plain
 * array rather than re-running the query on the worker. Dates are rendered in
 * the recipient's timezone.
 *
 * @phpstan-type Summary array{
 *     window_start: CarbonImmutable,
 *     window_end: CarbonImmutable,
 *     new_roles: int,
 *     companies_added: int,
 *     applications_sent: int,
 *     offers_recorded: int,
 *     follow_ups_due: int,
 *     top_signals: array<int, array{type: string, label: string, title: string, company: ?string, occurred_at: string}>,
 *     pipeline_trend: array<int, array{status: string, label: string, current: int, prior: int, delta: int}>,
 *     has_activity: bool
 * }
 */
final class WeeklySummaryMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  Summary  $summary
     */
    public function __construct(
        public User $user,
        public array $summary,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your week in review · '.$this->windowEnd()->format('M j'),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.weekly-summary',
            with: [
                'userName' => $this->user->getAttribute('name'),
                'windowStartLabel' => $this->windowStart()->format('M j'),
                'windowEndLabel' => $this->windowEnd()->format('M j, Y'),
                'newRoles' => $this->summary['new_roles'],
                'companiesAdded' => $this->summary['companies_added'],
                'applicationsSent' => $this->summary['applications_sent'],
                'offersRecorded' => $this->summary['offers_recorded'],
                'followUpsDue' => $this->summary['follow_ups_due'],
                'topSignals' => $this->summary['top_signals'],
                'pipelineTrend' => $this->summary['pipeline_trend'],
            ],
        );
    }

    private function windowStart(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->summary['window_start'])
            ->setTimezone($this->displayTimezone());
    }

    private function windowEnd(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->summary['window_end'])
            ->setTimezone($this->displayTimezone());
    }

    private function displayTimezone(): string
    {
        $timezone = $this->user->getAttribute('timezone');

        if (is_string($timezone) && $timezone !== '') {
            return $timezone;
        }

        return (string) config('notifications.timezone', 'UTC');
    }
}
