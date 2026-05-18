<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily roll-up of per-(source, field) parser confidence + correction counts.
 *
 * The /admin/parser dashboard reads from this table exclusively so the page
 * can render in sub-200ms regardless of how many raw listings exist. Raw
 * percentile aggregation against `aggregator_listings.parsed` jsonb is a
 * per-row cast + sort; we don't want that on every page load.
 *
 * We store p10/p50/p90 (not raw mean) because confidence distributions in
 * the live data are heavily bimodal: most fields cluster around 0.0
 * (MISSING) or 0.9 (matched). A median tells you the typical outcome; p10
 * surfaces the "worst 10%" worth investigating; p90 confirms the ceiling.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parser_confidence_daily', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('source_id')->constrained('aggregator_sources')->cascadeOnDelete();

            // Canonical ParsedListing field key (company_name, role_title, ...).
            $table->string('field');

            // UTC day boundary; the rollup command bucket-aligns on
            // (fetched_at AT TIME ZONE 'UTC')::date.
            $table->date('day');

            // Count of listings on that day where the field has a non-MISSING
            // confidence (> 0.0). n_missing counts the inverse.
            $table->unsignedInteger('n')->default(0);
            $table->unsignedInteger('n_missing')->default(0);

            // Confidence percentiles (0.0–1.0). decimal(5,4) — four decimals
            // covers the 0.001 step used by extractors.
            $table->decimal('p10', 5, 4)->nullable();
            $table->decimal('p50', 5, 4)->nullable();
            $table->decimal('p90', 5, 4)->nullable();

            // ParseCorrection rows that landed for that (source, field, day).
            $table->unsignedInteger('n_corrections')->default(0);

            $table->timestampsTz();

            $table->unique(
                ['tenant_id', 'source_id', 'field', 'day'],
                'parser_conf_daily_unique'
            );
            $table->index(['tenant_id', 'day', 'field'], 'parser_conf_daily_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parser_confidence_daily');
    }
};
