<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            $table->string('name');
            $table->string('type');
            $table->boolean('enabled')->default(true);
            $table->timestampTz('last_run_at')->nullable();
            $table->text('last_error')->nullable();

            $table->timestampsTz();

            $table->unique(['tenant_id', 'name']);
            $table->index(['tenant_id', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sources');
    }
};
