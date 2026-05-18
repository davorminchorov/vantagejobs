<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->date('snapshot_date');

            // Trend-bearing scalars. All nullable until their feeder job runs;
            // a snapshot row exists for every active company every day even when
            // signal contributors haven't been populated yet.
            $table->unsignedInteger('open_role_count')->default(0);
            $table->unsignedInteger('listings_count')->default(0);
            $table->string('tier', 8);
            $table->string('status');
            $table->unsignedTinyInteger('github_score')->nullable();
            $table->unsignedInteger('blog_posts_90d')->nullable();
            $table->boolean('has_recent_layoff_event')->default(false);
            $table->unsignedTinyInteger('quality_score')->nullable();
            $table->jsonb('breakdown')->nullable();

            $table->timestampsTz();

            $table->unique(['tenant_id', 'company_id', 'snapshot_date'], 'company_snapshots_tenant_company_day_unique');
            $table->index(['tenant_id', 'snapshot_date'], 'company_snapshots_tenant_day_idx');
            $table->index(['company_id', 'snapshot_date'], 'company_snapshots_company_day_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_snapshots');
    }
};
