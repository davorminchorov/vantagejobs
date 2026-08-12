<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\AuthRole;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class CreateAdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = app(TenantContext::class)->currentOrFail();

        $email = (string) env('INITIAL_USER_EMAIL', 'admin@vantagejobs.test');
        $name = (string) env('INITIAL_USER_NAME', 'Admin');
        $envPassword = env('INITIAL_USER_PASSWORD');

        $generatedPassword = null;
        if (empty($envPassword)) {
            $generatedPassword = Str::password(20);
            $password = $generatedPassword;
        } else {
            $password = (string) $envPassword;
        }

        $existing = User::query()->where('email', $email)->first();

        if ($existing === null) {
            $user = new User;
            $user->tenant_id = $tenant->id;
            $user->name = $name;
            $user->email = $email;
            $user->password = Hash::make($password);
            $user->save();
            $verb = 'created';
        } else {
            $existing->name = $name;
            $existing->tenant_id = $tenant->id;
            $existing->password = Hash::make($password);
            $existing->save();
            $user = $existing;
            $verb = 'updated';
        }

        $adminRole = AuthRole::firstOrCreate(['name' => 'admin']);
        $user->authRoles()->syncWithoutDetaching([$adminRole->id]);

        $this->command?->getOutput()->writeln(
            "  <info>Admin user {$verb}:</info> {$user->email} (id={$user->id})"
        );

        if ($generatedPassword !== null) {
            $output = $this->command?->getOutput();
            $output?->writeln('');
            $output?->writeln('  <comment>========================================================</comment>');
            $output?->writeln('  <comment>  GENERATED INITIAL PASSWORD (shown ONCE — save it now):</comment>');
            $output?->writeln("  <comment>  {$generatedPassword}</comment>");
            $output?->writeln('  <comment>========================================================</comment>');
            $output?->writeln('');
        }
    }
}
