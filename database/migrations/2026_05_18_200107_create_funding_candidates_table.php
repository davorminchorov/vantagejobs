<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('funding_candidates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            // 'google_news' for this signal — leaves room for other RSS sources
            // (e.g. Crunchbase) without a schema change.
            $table->string('source');

            // Article guid (preferred) or link, normalised by GoogleNewsClient.
            $table->string('external_id');

            $table->text('title');
            $table->text('url');
            $table->timestampTz('published_at')->nullable();

            // Preset keywords matched in the title — array of strings.
            $table->jsonb('matched_keywords');

            // Review-queue state: new | confirmed | dismissed.
            $table->string('status')->default('new');

            $table->timestampTz('fetched_at');

            $table->timestampsTz();

            $table->unique(
                ['tenant_id', 'company_id', 'source', 'external_id'],
                'funding_candidates_external_unique',
            );

            $table->index(
                ['tenant_id', 'status', 'fetched_at'],
                'funding_candidates_tenant_status_fetched_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('funding_candidates');
    }
};
