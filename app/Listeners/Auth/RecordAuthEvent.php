<?php

declare(strict_types=1);

namespace App\Listeners\Auth;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Laravel\Fortify\Events\PasswordUpdatedViaController;
use Laravel\Fortify\Events\RecoveryCodeReplaced;
use Laravel\Fortify\Events\TwoFactorAuthenticationChallenged;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Laravel\Fortify\Events\ValidTwoFactorAuthenticationCodeProvided;

/**
 * Records security-sensitive auth events to the audit_logs table.
 *
 * Inject Request via the container so we capture the IP and User-Agent of
 * the request that triggered the event. Never persist passwords, plaintext
 * 2FA codes, or recovery tokens — only the email associated with a failed
 * attempt, plus a small set of safe attributes.
 */
final readonly class RecordAuthEvent
{
    public function __construct(private Request $request) {}

    public function handleLogin(Login $event): void
    {
        $this->record(
            action: 'auth.login.success',
            outcome: 'success',
            user: $event->user instanceof User ? $event->user : null,
            context: ['guard' => $event->guard, 'remember' => (bool) $event->remember],
        );
    }

    public function handleFailed(Failed $event): void
    {
        $email = is_array($event->credentials) && isset($event->credentials['email'])
            ? (string) $event->credentials['email']
            : null;

        $this->record(
            action: 'auth.login.failed',
            outcome: 'failure',
            user: $event->user instanceof User ? $event->user : null,
            context: ['guard' => $event->guard, 'email' => $email],
        );
    }

    public function handleLogout(Logout $event): void
    {
        $this->record(
            action: 'auth.logout',
            outcome: 'success',
            user: $event->user instanceof User ? $event->user : null,
            context: ['guard' => $event->guard],
        );
    }

    public function handleLockout(Lockout $event): void
    {
        $email = $event->request->input('email');

        $this->record(
            action: 'auth.login.lockout',
            outcome: 'failure',
            user: null,
            context: ['email' => is_string($email) ? $email : null],
            ip: $event->request->ip(),
            userAgent: $event->request->userAgent(),
        );
    }

    public function handlePasswordReset(PasswordReset $event): void
    {
        $this->record(
            action: 'auth.password.reset',
            outcome: 'success',
            user: $event->user instanceof User ? $event->user : null,
        );
    }

    public function handlePasswordUpdated(PasswordUpdatedViaController $event): void
    {
        $this->record(
            action: 'auth.password.updated',
            outcome: 'success',
            user: $event->user instanceof User ? $event->user : null,
        );
    }

    public function handleTwoFactorEnabled(TwoFactorAuthenticationEnabled $event): void
    {
        $this->record(
            action: 'auth.2fa.enabled',
            outcome: 'success',
            user: $event->user instanceof User ? $event->user : null,
        );
    }

    public function handleTwoFactorConfirmed(TwoFactorAuthenticationConfirmed $event): void
    {
        $this->record(
            action: 'auth.2fa.confirmed',
            outcome: 'success',
            user: $event->user instanceof User ? $event->user : null,
        );
    }

    public function handleTwoFactorDisabled(TwoFactorAuthenticationDisabled $event): void
    {
        $this->record(
            action: 'auth.2fa.disabled',
            outcome: 'success',
            user: $event->user instanceof User ? $event->user : null,
        );
    }

    public function handleTwoFactorChallenged(TwoFactorAuthenticationChallenged $event): void
    {
        $this->record(
            action: 'auth.2fa.challenged',
            outcome: 'success',
            user: $event->user instanceof User ? $event->user : null,
        );
    }

    public function handleTwoFactorFailed(TwoFactorAuthenticationFailed $event): void
    {
        $this->record(
            action: 'auth.2fa.failed',
            outcome: 'failure',
            user: $event->user instanceof User ? $event->user : null,
        );
    }

    public function handleRecoveryCodeReplaced(RecoveryCodeReplaced $event): void
    {
        $this->record(
            action: 'auth.2fa.recovery_code_used',
            outcome: 'success',
            user: $event->user instanceof User ? $event->user : null,
        );
    }

    public function handleValidTwoFactorCode(ValidTwoFactorAuthenticationCodeProvided $event): void
    {
        $this->record(
            action: 'auth.2fa.success',
            outcome: 'success',
            user: $event->user instanceof User ? $event->user : null,
        );
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function record(
        string $action,
        string $outcome,
        ?User $user,
        array $context = [],
        ?string $ip = null,
        ?string $userAgent = null,
    ): void {
        AuditLog::query()->create([
            'tenant_id' => $user?->tenant_id,
            'actor_id' => $user?->getKey(),
            'action' => $action,
            'outcome' => $outcome,
            'ip' => $ip ?? $this->request->ip(),
            'user_agent' => $userAgent ?? $this->request->userAgent(),
            'context' => $context,
        ]);
    }
}
