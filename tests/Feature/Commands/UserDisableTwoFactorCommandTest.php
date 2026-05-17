<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Models\AuditLog;
use App\Models\User;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class UserDisableTwoFactorCommandTest extends TestCase
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
    public function it_clears_two_factor_columns_and_writes_audit_log(): void
    {
        $user = User::factory()->create();
        $user->two_factor_secret = 'encrypted-secret-value';
        $user->two_factor_recovery_codes = 'encrypted-recovery-codes-blob';
        $user->two_factor_confirmed_at = now();
        $user->save();

        $this->artisan('user:disable-2fa')
            ->expectsOutputToContain('Two-factor authentication disabled')
            ->assertSuccessful();

        $user->refresh();
        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_recovery_codes);
        $this->assertNull($user->two_factor_confirmed_at);

        $log = AuditLog::query()->where('action', 'auth.2fa.disabled.cli')->sole();
        $this->assertSame('success', $log->outcome);
        $this->assertSame($user->id, $log->actor_id);
    }

    #[Test]
    public function it_fails_when_no_user_exists(): void
    {
        $this->artisan('user:disable-2fa')
            ->expectsOutputToContain('No user found.')
            ->assertExitCode(1);
    }
}
