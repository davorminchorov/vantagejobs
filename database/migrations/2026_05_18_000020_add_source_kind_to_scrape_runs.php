<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scrape_runs', function (Blueprint $table): void {
            // 'ats' = Phase 1 per-company ATS scrape; 'aggregator' = Phase 2 source scrape.
            // Aggregator rows leave company_id null and pin source_id via the source string.
            $table->string('source_kind')->default('ats')->after('source');
            $table->index(['tenant_id', 'source_kind', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::table('scrape_runs', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'source_kind', 'started_at']);
            $table->dropColumn('source_kind');
        });
    }
};
