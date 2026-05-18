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
            $table->boolean('ghost_flag')->default(false)->after('requisition_id');
            $table->jsonb('ghost_reasons')->nullable()->after('ghost_flag');

            $table->index(['tenant_id', 'ghost_flag'], 'aggregator_listings_tenant_ghost_idx');
        });
    }

    public function down(): void
    {
        Schema::table('aggregator_listings', function (Blueprint $table): void {
            $table->dropIndex('aggregator_listings_tenant_ghost_idx');
            $table->dropColumn(['ghost_flag', 'ghost_reasons']);
        });
    }
};
