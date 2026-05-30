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
 * Queued alert raised when a backup operation fails — either a pg_dump run
 * that exited non-zero or a monthly restore verification whose archive was
 * unreadable / had an empty table of contents.
 *
 * Dispatched by BackupRunCommand and BackupVerifyCommand. Provider is
 * config-driven via MAIL_MAILER — no provider SDK is hardcoded. The body is a
 * Markdown template.
 */
final class BackupFailedMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  'run'|'verify'  $operation
     */
    public function __construct(
        public string $operation,
        public string $reason,
        public ?string $backupKey = null,
    ) {}

    public function envelope(): Envelope
    {
        $label = $this->operation === 'verify' ? 'verification' : 'run';

        return new Envelope(
            subject: 'Backup '.$label.' failed',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.backup-failed',
            with: [
                'operation' => $this->operation,
                'reason' => $this->reason,
                'backupKey' => $this->backupKey,
            ],
        );
    }
}
