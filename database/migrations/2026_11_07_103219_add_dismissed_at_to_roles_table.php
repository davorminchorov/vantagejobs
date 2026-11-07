<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->timestampTz('dismissed_at')->nullable();

            $table->index(['tenant_id', 'is_open', 'dismissed_at'], 'roles_tenant_open_dismissed_idx');
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->dropIndex('roles_tenant_open_dismissed_idx');
            $table->dropColumn('dismissed_at');
        });
    }
};
