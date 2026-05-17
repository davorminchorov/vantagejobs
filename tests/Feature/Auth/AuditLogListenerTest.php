<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Laravel\Fortify\Events\PasswordUpdatedViaController;
use Laravel\Fortify\Events\RecoveryCodeReplaced;
use Laravel\Fortify\Events\TwoFactorAuthenticationChallenged;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Laravel\Fortify\Events\ValidTwoFactorAuthenticationCodeProvided;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AuditLogListenerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function login_event_writes_audit_log(): void
    {
        $user = User::factory()->create();

        event(new Login('web', $user, false));

        $log = AuditLog::query()->where('action', 'auth.login.success')->sole();
        $this->assertSame('success', $log->outcome);
        $this->assertSame($user->id, $log->actor_id);
        $this->assertSame($user->tenant_id, $log->tenant_id);
        $this->assertSame('web', $log->context['guard']);
        $this->assertFalse($log->context['remember']);
    }

    #[Test]
    public function failed_event_writes_audit_log_with_email_but_no_password(): void
    {
        event(new Failed('web', null, ['email' => 'attacker@example.com', 'password' => 'p4ssw0rd']));

        $log = AuditLog::query()->where('action', 'auth.login.failed')->sole();
        $this->assertSame('failure', $log->outcome);
        $this->assertNull($log->actor_id);
        $this->assertNull($log->tenant_id);
        $this->assertSame('attacker@example.com', $log->context['email']);
        $this->assertArrayNotHasKey('password', $log->context);
    }

    #[Test]
    public function audit_log_does_not_record_password_or_tokens_in_context_for_failed_login(): void
    {
        event(new Failed('web', null, [
            'email' => 'attacker@example.com',
            'password' => 'super-secret',
            'token' => 'tok_abc123',
        ]));

        $log = AuditLog::query()->where('action', 'auth.login.failed')->sole();

        $encoded = json_encode($log->context);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('super-secret', $encoded);
        $this->assertStringNotContainsString('tok_abc123', $encoded);
        $this->assertArrayNotHasKey('password', $log->context);
        $this->assertArrayNotHasKey('token', $log->context);
    }

    #[Test]
    public function logout_event_writes_audit_log(): void
    {
        $user = User::factory()->create();

        event(new Logout('web', $user));

        $log = AuditLog::query()->where('action', 'auth.logout')->sole();
        $this->assertSame('success', $log->outcome);
        $this->assertSame($user->id, $log->actor_id);
        $this->assertSame($user->tenant_id, $log->tenant_id);
    }

    #[Test]
    public function lockout_event_writes_audit_log_with_null_actor(): void
    {
        $request = Request::create('/login', 'POST', ['email' => 'victim@example.com']);
        $request->server->set('REMOTE_ADDR', '203.0.113.7');
        $request->headers->set('User-Agent', 'PHPUnit/Lockout');

        event(new Lockout($request));

        $log = AuditLog::query()->where('action', 'auth.login.lockout')->sole();
        $this->assertSame('failure', $log->outcome);
        $this->assertNull($log->actor_id);
        $this->assertNull($log->tenant_id);
        $this->assertSame('victim@example.com', $log->context['email']);
        $this->assertSame('203.0.113.7', $log->ip);
        $this->assertSame('PHPUnit/Lockout', $log->user_agent);
    }

    #[Test]
    public function audit_log_records_null_actor_id_and_tenant_id_for_unauthenticated_lockout(): void
    {
        $request = Request::create('/login', 'POST', []);

        event(new Lockout($request));

        $log = AuditLog::query()->where('action', 'auth.login.lockout')->sole();
        $this->assertNull($log->actor_id);
        $this->assertNull($log->tenant_id);
        $this->assertNull($log->context['email']);
    }

    #[Test]
    public function password_reset_event_writes_audit_log(): void
    {
        $user = User::factory()->create();

        event(new PasswordReset($user));

        $log = AuditLog::query()->where('action', 'auth.password.reset')->sole();
        $this->assertSame('success', $log->outcome);
        $this->assertSame($user->id, $log->actor_id);
        $this->assertSame($user->tenant_id, $log->tenant_id);
    }

    #[Test]
    public function password_updated_event_writes_audit_log(): void
    {
        $user = User::factory()->create();

        event(new PasswordUpdatedViaController($user));

        $log = AuditLog::query()->where('action', 'auth.password.updated')->sole();
        $this->assertSame('success', $log->outcome);
        $this->assertSame($user->id, $log->actor_id);
        $this->assertSame($user->tenant_id, $log->tenant_id);
    }

    #[Test]
    public function two_factor_enabled_event_writes_audit_log(): void
    {
        $user = User::factory()->create();

        event(new TwoFactorAuthenticationEnabled($user));

        $log = AuditLog::query()->where('action', 'auth.2fa.enabled')->sole();
        $this->assertSame('success', $log->outcome);
        $this->assertSame($user->id, $log->actor_id);
        $this->assertSame($user->tenant_id, $log->tenant_id);
    }

    #[Test]
    public function two_factor_confirmed_event_writes_audit_log(): void
    {
        $user = User::factory()->create();

        event(new TwoFactorAuthenticationConfirmed($user));

        $log = AuditLog::query()->where('action', 'auth.2fa.confirmed')->sole();
        $this->assertSame('success', $log->outcome);
        $this->assertSame($user->id, $log->actor_id);
    }

    #[Test]
    public function two_factor_disabled_event_writes_audit_log(): void
    {
        $user = User::factory()->create();

        event(new TwoFactorAuthenticationDisabled($user));

        $log = AuditLog::query()->where('action', 'auth.2fa.disabled')->sole();
        $this->assertSame('success', $log->outcome);
        $this->assertSame($user->id, $log->actor_id);
    }

    #[Test]
    public function two_factor_challenged_event_writes_audit_log(): void
    {
        $user = User::factory()->create();

        event(new TwoFactorAuthenticationChallenged($user));

        $log = AuditLog::query()->where('action', 'auth.2fa.challenged')->sole();
        $this->assertSame('success', $log->outcome);
        $this->assertSame($user->id, $log->actor_id);
    }

    #[Test]
    public function two_factor_failed_event_writes_audit_log(): void
    {
        $user = User::factory()->create();

        event(new TwoFactorAuthenticationFailed($user));

        $log = AuditLog::query()->where('action', 'auth.2fa.failed')->sole();
        $this->assertSame('failure', $log->outcome);
        $this->assertSame($user->id, $log->actor_id);
        $this->assertSame($user->tenant_id, $log->tenant_id);
    }

    #[Test]
    public function recovery_code_replaced_event_writes_audit_log_without_the_code(): void
    {
        $user = User::factory()->create();

        event(new RecoveryCodeReplaced($user, 'recovery-code-secret'));

        $log = AuditLog::query()->where('action', 'auth.2fa.recovery_code_used')->sole();
        $this->assertSame('success', $log->outcome);
        $this->assertSame($user->id, $log->actor_id);

        $encoded = json_encode($log->context);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('recovery-code-secret', $encoded);
    }

    #[Test]
    public function valid_two_factor_code_provided_event_writes_audit_log(): void
    {
        $user = User::factory()->create();

        event(new ValidTwoFactorAuthenticationCodeProvided($user));

        $log = AuditLog::query()->where('action', 'auth.2fa.success')->sole();
        $this->assertSame('success', $log->outcome);
        $this->assertSame($user->id, $log->actor_id);
        $this->assertSame($user->tenant_id, $log->tenant_id);
    }
}
