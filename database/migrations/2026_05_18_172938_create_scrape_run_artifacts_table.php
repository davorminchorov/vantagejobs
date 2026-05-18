<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scrape_run_artifacts', function (Blueprint $table): void {
            $table->id();

            // Tenant is nullable because some connector calls (e.g., a future
            // shared discovery probe) may not yet have a tenant in scope when
            // the Saloon middleware fires. Per-tenant rows still index well.
            $table->foreignId('tenant_id')->nullable()
                ->constrained('tenants')->cascadeOnDelete();

            // Best-effort link to the scrape_runs row that owned the request.
            // Null when the middleware fires outside an orchestrator (e.g., a
            // /discover/sources "Run now" path that hasn't opened a run yet,
            // or an ad-hoc tinker call). The retention sweep still finds these.
            $table->foreignId('scrape_run_id')->nullable()
                ->constrained('scrape_runs')->nullOnDelete();

            // 'ats' | 'aggregator' — mirrors scrape_runs.source_kind so artifacts
            // can be browsed without joining when the run row was nulled out.
            $table->string('source_kind');

            // aggregator_sources.id when source_kind = 'aggregator'. Not a FK
            // because Phase 1 ATS artifacts use companies.id in company_id.
            $table->unsignedBigInteger('source_id')->nullable();

            // companies.id when source_kind = 'ats'.
            $table->foreignId('company_id')->nullable()
                ->constrained('companies')->nullOnDelete();

            // Short connector identifier ("remoteok", "hackernews_algolia",
            // "weworkremotely", etc.). Used to pick filesystem subdirectory.
            $table->string('connector');

            // Full URL the request hit. Long enough for query strings.
            $table->text('request_endpoint');

            // Null when the request errored before a response landed.
            $table->integer('http_status')->nullable();

            // Relative path under the configured scrape-artifacts disk.
            $table->text('path');

            $table->unsignedInteger('size_bytes');

            // sha256 of the gzipped body — deduplication + tamper detection.
            $table->string('sha256', 64);

            // fetched_at + retention window. The purge command sweeps any row
            // whose retained_until has elapsed.
            $table->timestampTz('retained_until');

            $table->timestampTz('fetched_at');

            $table->timestampsTz();

            $table->index(['tenant_id', 'fetched_at']);
            $table->index('retained_until');
            $table->index('scrape_run_id');
            $table->index('sha256');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scrape_run_artifacts');
    }
};
