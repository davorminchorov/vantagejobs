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
            $table->char('role_hash', 64)->nullable()->after('location_bucket');
            $table->string('requisition_id')->nullable()->after('role_hash');
            $table->index(['tenant_id', 'role_hash'], 'aggregator_listings_tenant_role_hash_idx');
            $table->index(['tenant_id', 'requisition_id'], 'aggregator_listings_tenant_req_id_idx');
        });
    }

    public function down(): void
    {
        Schema::table('aggregator_listings', function (Blueprint $table): void {
            $table->dropIndex('aggregator_listings_tenant_role_hash_idx');
            $table->dropIndex('aggregator_listings_tenant_req_id_idx');
            $table->dropColumn(['role_hash', 'requisition_id']);
        });
    }
};
