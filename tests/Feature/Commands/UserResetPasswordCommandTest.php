<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Models\AuditLog;
use App\Models\User;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class UserResetPasswordCommandTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['slug' => 'default']);
        app(TenantContext::class)->setCurrent($this->tenant);
    }

    #[Test]
    public function it_updates_password_and_writes_audit_log(): void
    {
        $user = User::factory()->create(['email' => 'admin@vantagejobs.test']);

        $this->artisan('user:reset-password')
            ->expectsQuestion('New password (min 12 chars)', 'new-strong-password-123')
            ->expectsQuestion('Confirm new password', 'new-strong-password-123')
            ->expectsOutputToContain('Password updated.')
            ->assertSuccessful();

        $user->refresh();
        $this->assertTrue(Hash::check('new-strong-password-123', $user->password));

        $log = AuditLog::query()->where('action', 'auth.password.reset.cli')->sole();
        $this->assertSame('success', $log->outcome);
        $this->assertSame($user->id, $log->actor_id);
        $this->assertSame('cli', $log->context['source']);
    }

    #[Test]
    public function it_fails_when_no_user_exists(): void
    {
        $this->artisan('user:reset-password')
            ->expectsOutputToContain('No user found.')
            ->assertExitCode(1);
    }

    #[Test]
    public function it_fails_when_passwords_do_not_match(): void
    {
        $user = User::factory()->create();
        $originalHash = $user->password;

        $this->artisan('user:reset-password')
            ->expectsQuestion('New password (min 12 chars)', 'new-strong-password-123')
            ->expectsQuestion('Confirm new password', 'something-else-entirely')
            ->expectsOutputToContain('Passwords do not match.')
            ->assertExitCode(1);

        $user->refresh();
        $this->assertSame($originalHash, $user->password);
    }

    #[Test]
    public function it_fails_when_password_is_too_short(): void
    {
        $user = User::factory()->create();
        $originalHash = $user->password;

        $this->artisan('user:reset-password')
            ->expectsQuestion('New password (min 12 chars)', 'short')
            ->expectsQuestion('Confirm new password', 'short')
            ->expectsOutputToContain('at least 12 characters')
            ->assertExitCode(1);

        $user->refresh();
        $this->assertSame($originalHash, $user->password);
    }
}
