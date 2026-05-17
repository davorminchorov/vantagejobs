<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

final class UserResetPasswordCommand extends Command
{
    protected $signature = 'user:reset-password';

    protected $description = 'Reset the password for the single application user (offline recovery).';

    public function handle(): int
    {
        $count = User::withoutTenantScope()->count();

        if ($count === 0) {
            $this->error('No user found. Run `php artisan db:seed` first to create the initial user.');

            return self::FAILURE;
        }

        if ($count > 1) {
            $this->error("Expected exactly one user, found {$count}. This command is for the single-user v1 deployment.");

            return self::FAILURE;
        }

        /** @var User $user */
        $user = User::withoutTenantScope()->first();

        $this->info("Resetting password for: {$user->email}");

        $password = $this->secret('New password (min 12 chars)');
        $confirm = $this->secret('Confirm new password');

        if ($password !== $confirm) {
            $this->error('Passwords do not match. Aborted — password unchanged.');

            return self::FAILURE;
        }

        if (! is_string($password) || strlen($password) < 12) {
            $this->error('Password must be at least 12 characters. Aborted — password unchanged.');

            return self::FAILURE;
        }

        $user->password = Hash::make($password);
        $user->save();

        AuditLog::query()->create([
            'tenant_id' => $user->tenant_id,
            'actor_id' => $user->id,
            'action' => 'auth.password.reset.cli',
            'outcome' => 'success',
            'ip' => null,
            'user_agent' => null,
            'context' => ['user_id' => $user->id, 'source' => 'cli'],
        ]);

        $this->info('Password updated.');

        return self::SUCCESS;
    }
}
