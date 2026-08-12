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
 * Queued fix-me alert for an ATS-tracked company that has gone unhealthy —
 * either a recent scrape run ended in `error`/`partial`, or the ATS-switch
 * detector flagged the company as `needs_redetection`/`redetect_failed`.
 * Dispatched by the monitor:source-health sweep, deduped via a
 * NotificationLogEntry keyed by company_id so a company only alerts once
 * while it stays red.
 *
 * Provider is config-driven via MAIL_MAILER — no provider SDK is hardcoded.
 * The body is a Markdown template.
 *
 * @param  list<string>  $reasons
 */
final class AtsHealthMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  list<string>  $reasons
     */
    public function __construct(
        public int $companyId,
        public string $companyName,
        public array $reasons,
        public int $consecutive404s,
        public ?string $lastRunAt,
        public string $companyUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'ATS health: '.$this->companyName.' needs attention',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.ats-health',
            with: [
                'companyName' => $this->companyName,
                'reasons' => $this->reasons,
                'consecutive404s' => $this->consecutive404s,
                'lastRunAt' => $this->lastRunAt,
                'companyUrl' => $this->companyUrl,
            ],
        );
    }
}
