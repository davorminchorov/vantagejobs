<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\CalendarEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Queued reminder for an upcoming calendar event. Dispatched by the
 * calendar:send-reminders sweep once an event crosses its reminder lead-time.
 *
 * Provider is config-driven via MAIL_MAILER — no provider SDK is hardcoded.
 * The body is a Markdown template; the user's stored IANA timezone is used to
 * render a human-readable local start time (the event's starts_at is stored as
 * canonical UTC).
 */
final class CalendarEventReminderMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public CalendarEvent $event,
        public User $user,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Reminder: '.$this->event->title,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.calendar-reminder',
            with: [
                'title' => $this->event->title,
                'localStartTime' => $this->localStartTime(),
                'timezone' => $this->displayTimezone(),
                'notes' => $this->event->notes,
                'url' => $this->event->url,
            ],
        );
    }

    /**
     * Render the UTC start time in the user's timezone (falling back to the
     * event's original IANA zone, then UTC) for display in the email body.
     */
    private function localStartTime(): string
    {
        return CarbonImmutable::parse($this->event->starts_at)
            ->setTimezone($this->displayTimezone())
            ->format('l, F j, Y \a\t g:i A T');
    }

    private function displayTimezone(): string
    {
        $userTimezone = $this->user->getAttribute('timezone');

        if (is_string($userTimezone) && $userTimezone !== '') {
            return $userTimezone;
        }

        if (is_string($this->event->timezone) && $this->event->timezone !== '') {
            return $this->event->timezone;
        }

        return 'UTC';
    }
}
