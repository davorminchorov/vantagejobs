<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\AuthRole;
use Illuminate\Database\Seeder;

final class AuthRolesSeeder extends Seeder
{
    public function run(): void
    {
        AuthRole::firstOrCreate(['name' => 'admin']);
    }
}
