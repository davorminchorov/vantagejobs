<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Global, not tenant-scoped: role names are universal in this
        // single-tenant app, and seeders/console commands run without a
        // TenantContext.
        Schema::create('auth_roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->timestampsTz();
        });

        Schema::create('auth_role_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('auth_role_id')->constrained('auth_roles')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestampsTz();

            $table->unique(['auth_role_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_role_user');
        Schema::dropIfExists('auth_roles');
    }
};
