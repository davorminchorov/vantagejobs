<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aggregator_listings', function (Blueprint $table): void {
            $table->string('discipline')->nullable()->after('parsed_seniority');

            $table->index(['tenant_id', 'discipline'], 'aggregator_listings_tenant_discipline_idx');
        });
    }

    public function down(): void
    {
        Schema::table('aggregator_listings', function (Blueprint $table): void {
            $table->dropIndex('aggregator_listings_tenant_discipline_idx');
            $table->dropColumn('discipline');
        });
    }
};
