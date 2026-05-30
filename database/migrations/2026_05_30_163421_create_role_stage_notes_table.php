<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_stage_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();

            $table->string('stage');
            $table->text('body');

            // Append-only — created_at only, no updated_at.
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['tenant_id', 'role_id', 'stage']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_stage_notes');
    }
};
