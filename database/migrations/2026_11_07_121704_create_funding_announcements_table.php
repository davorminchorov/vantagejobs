<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Funding headlines from startup news feeds (TechCrunch, UKTN, tech.eu, ...)
 * about companies that are not necessarily tracked yet. Feeds the
 * Insights > Recently funded discovery list. Separate from
 * funding_candidates, which only holds news about tracked companies.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('funding_announcements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // App\Signals\Funding\FundingFeedSource value.
            $table->string('source');
            // RSS guid, or the link when there is none.
            $table->text('external_id');

            $table->text('title');
            $table->text('url');
            $table->timestampTz('published_at');

            // Parsed from the headline; null when the headline does not say.
            $table->string('company_name')->nullable();
            $table->string('company_key')->nullable();
            $table->string('stage', 32)->nullable();
            $table->unsignedBigInteger('amount_usd')->nullable();
            $table->string('amount_label', 32)->nullable();

            // new | dismissed | tracked
            $table->string('status')->default('new');
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();

            $table->timestampsTz();

            $table->unique(['tenant_id', 'source', 'external_id'], 'funding_announcements_external_unique');
            $table->index(['tenant_id', 'status', 'published_at'], 'funding_announcements_status_published_idx');
            $table->index(['tenant_id', 'company_key'], 'funding_announcements_company_key_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('funding_announcements');
    }
};
