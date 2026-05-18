<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aggregator_listings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('source_id')->constrained('aggregator_sources')->cascadeOnDelete();

            // Stable per-source identifier. HN comment id, Remotive job id, RemoteOK id,
            // Himalayas guid, WWR RSS guid.
            $table->string('external_id');

            $table->text('url')->nullable();

            // Full original source record, unchanged. Survives parser revisions.
            $table->jsonb('raw');

            // ParsedListing output: nested {field: {value, confidence, source, evidence}}.
            // Phase 3 inspector reads this directly.
            $table->jsonb('parsed')->nullable();

            // Denormalized parsed fields. Filters in /discover hit these, not the blob.
            $table->string('parsed_company_name')->nullable();
            $table->string('parsed_role_title')->nullable();
            $table->string('parsed_seniority')->nullable();
            $table->string('parsed_remote_type')->nullable();
            $table->unsignedInteger('parsed_comp_min_usd')->nullable();
            $table->unsignedInteger('parsed_comp_max_usd')->nullable();
            $table->boolean('has_comp')->default(false);
            $table->jsonb('stack_tags')->nullable();

            // Aggressively normalized keys for dedup + auto-link lookups.
            // Preserves original spelling in parsed_company_name.
            $table->string('normalized_company_key')->nullable();
            $table->string('normalized_role_key')->nullable();
            $table->string('location_bucket')->nullable();

            // AggregatorListingStatus backing value.
            $table->string('status')->default('new');

            $table->foreignId('matched_company_id')->nullable()
                ->constrained('companies')->nullOnDelete();
            $table->foreignId('duplicate_of_id')->nullable()
                ->constrained('aggregator_listings')->nullOnDelete();

            $table->timestampTz('posted_at')->nullable();
            $table->timestampTz('fetched_at');
            $table->timestampTz('snoozed_until')->nullable();

            $table->timestampsTz();

            $table->unique(['tenant_id', 'source_id', 'external_id']);
            $table->index(['tenant_id', 'status', 'posted_at']);
            $table->index(['tenant_id', 'normalized_company_key']);
            $table->index(['tenant_id', 'matched_company_id']);
            // 90-day retention sweep filter.
            $table->index(['tenant_id', 'fetched_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aggregator_listings');
    }
};
