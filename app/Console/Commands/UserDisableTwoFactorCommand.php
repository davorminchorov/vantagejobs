<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Console\Command;

final class UserDisableTwoFactorCommand extends Command
{
    protected $signature = 'user:disable-2fa';

    protected $description = 'Clear the single user\'s two-factor secret, recovery codes, and confirmation (offline recovery).';

    public function handle(): int
    {
        $count = User::withoutTenantScope()->count();

        if ($count === 0) {
            $this->error('No user found.');

            return self::FAILURE;
        }

        if ($count > 1) {
            $this->error("Expected exactly one user, found {$count}. This command is for the single-user v1 deployment.");

            return self::FAILURE;
        }

        /** @var User $user */
        $user = User::withoutTenantScope()->first();

        $user->two_factor_secret = null;
        $user->two_factor_recovery_codes = null;
        $user->two_factor_confirmed_at = null;
        $user->save();

        AuditLog::query()->create([
            'tenant_id' => $user->tenant_id,
            'actor_id' => $user->id,
            'action' => 'auth.2fa.disabled.cli',
            'outcome' => 'success',
            'ip' => null,
            'user_agent' => null,
            'context' => ['user_id' => $user->id, 'source' => 'cli'],
        ]);

        $this->info("Two-factor authentication disabled for {$user->email}.");

        return self::SUCCESS;
    }
}
