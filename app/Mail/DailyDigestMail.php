<?php

declare(strict_types=1);

namespace App\Mail;

use App\Notifications\DailyDigest;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Queued daily digest of tracked-company activity, the discover backlog, stale
 * A-tier targets, and the follow-up queue. Dispatched once per tenant per day
 * by the digest:send-daily sweep at the recipient's configured local time.
 *
 * Provider is config-driven via MAIL_MAILER — no provider SDK is hardcoded.
 * The body is a Markdown template. The DailyDigest payload is a plain readonly
 * value object (no Eloquent models requiring requeue hydration beyond the
 * grouped events it carries), so it serializes cleanly onto the queue.
 */
final class DailyDigestMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public DailyDigest $digest,
        public string $timezone,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->subjectLine(),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.daily-digest',
            with: [
                'digest' => $this->digest,
                'localDate' => $this->localDate(),
                'timezone' => $this->timezone,
                'eventCount' => $this->digest->eventCount(),
            ],
        );
    }

    /**
     * Subject summarises the headline counts so the digest is scannable from
     * the inbox without opening it.
     */
    private function subjectLine(): string
    {
        $events = $this->digest->eventCount();
        $discover = $this->digest->discoverQueueCount;

        return "Daily digest: {$events} updates, {$discover} to triage";
    }

    /**
     * Today's date in the recipient timezone, for the digest header.
     */
    private function localDate(): string
    {
        return CarbonImmutable::now()
            ->setTimezone($this->timezone)
            ->format('l, F j, Y');
    }
}
