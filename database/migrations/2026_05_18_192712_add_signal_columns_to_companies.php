<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->string('funding_stage')->nullable()->after('status');
            $table->timestampTz('funding_last_raised_at')->nullable()->after('funding_stage');
            $table->bigInteger('funding_last_amount_usd')->nullable()->after('funding_last_raised_at');
            $table->string('github_org')->nullable()->after('funding_last_amount_usd');
            $table->string('eng_blog_rss_url', 1024)->nullable()->after('github_org');
            $table->boolean('is_active')->default(true)->after('eng_blog_rss_url');
            $table->string('ats_status')->default('ok')->after('is_active');
            $table->unsignedSmallInteger('ats_consecutive_404s')->default(0)->after('ats_status');
        });

        // Partial index so default queries that exclude graveyard rows stay cheap.
        DB::statement('CREATE INDEX companies_tenant_active_idx ON companies (tenant_id) WHERE is_active = true');
        // Help the redetection sweep.
        DB::statement("CREATE INDEX companies_tenant_ats_status_idx ON companies (tenant_id, ats_status) WHERE ats_status <> 'ok'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS companies_tenant_active_idx');
        DB::statement('DROP INDEX IF EXISTS companies_tenant_ats_status_idx');

        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn([
                'funding_stage',
                'funding_last_raised_at',
                'funding_last_amount_usd',
                'github_org',
                'eng_blog_rss_url',
                'is_active',
                'ats_status',
                'ats_consecutive_404s',
            ]);
        });
    }
};
