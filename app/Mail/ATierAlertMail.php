<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Company;
use App\Models\Role;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Queued instant alert for one A-tier company that has just posted one or more
 * new open roles. Dispatched by the notifications:send-a-tier-alerts sweep, at
 * most once per company per throttle window.
 *
 * Provider is config-driven via MAIL_MAILER — no provider SDK is hardcoded.
 * The body is a Markdown template listing every new role grouped under the
 * company in a single email.
 */
final class ATierAlertMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  Collection<int, Role>  $roles
     */
    public function __construct(
        public Company $company,
        public Collection $roles,
    ) {}

    public function envelope(): Envelope
    {
        $count = $this->roles->count();
        $plural = $count === 1 ? 'role' : 'roles';

        return new Envelope(
            subject: "A-tier alert: {$count} new {$plural} at {$this->company->name}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.a-tier-alert',
            with: [
                'companyName' => $this->company->name,
                'roles' => $this->roles,
            ],
        );
    }
}
