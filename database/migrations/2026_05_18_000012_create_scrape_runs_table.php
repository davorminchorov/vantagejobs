<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scrape_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();

            $table->string('source');
            $table->string('status')->default('running');

            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();

            $table->unsignedInteger('fetched')->default(0);
            $table->unsignedInteger('new_count')->default(0);
            $table->unsignedInteger('closed_count')->default(0);

            $table->jsonb('errors')->nullable();

            $table->timestampsTz();

            $table->index(['tenant_id', 'source', 'started_at']);
            $table->index(['tenant_id', 'company_id', 'started_at']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scrape_runs');
    }
};
