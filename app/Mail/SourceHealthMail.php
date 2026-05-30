<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Queued fix-me alert for an aggregator source that has gone unhealthy — either
 * a sustained run of failed scheduled scrapes or a sharp week-over-week drop in
 * parse confidence. Dispatched by the monitor:source-health sweep, deduped via
 * a NotificationLogEntry so a source only alerts once while it stays red.
 *
 * Provider is config-driven via MAIL_MAILER — no provider SDK is hardcoded.
 * The body is a Markdown template.
 *
 * @param  list<string>  $reasons
 */
final class SourceHealthMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  list<string>  $reasons
     */
    public function __construct(
        public string $sourceName,
        public string $sourceKey,
        public array $reasons,
        public int $consecutiveFailures,
        public ?string $lastError,
        public ?string $lastRunAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Source health: '.$this->sourceName.' needs attention',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.source-health',
            with: [
                'sourceName' => $this->sourceName,
                'sourceKey' => $this->sourceKey,
                'reasons' => $this->reasons,
                'consecutiveFailures' => $this->consecutiveFailures,
                'lastError' => $this->lastError,
                'lastRunAt' => $this->lastRunAt,
            ],
        );
    }
}
