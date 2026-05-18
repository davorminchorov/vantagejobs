<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aggregator_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // AggregatorSourceType backing value: hn | remotive | himalayas | wwr | remoteok.
            $table->string('type');
            $table->string('name');

            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('cadence_minutes');

            $table->timestampTz('last_run_at')->nullable();
            $table->timestampTz('next_run_at')->nullable();
            $table->text('last_error')->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);

            $table->timestampsTz();

            $table->unique(['tenant_id', 'type']);
            $table->index(['tenant_id', 'enabled', 'next_run_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aggregator_sources');
    }
};
