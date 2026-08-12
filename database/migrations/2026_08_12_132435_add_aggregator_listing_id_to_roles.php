<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->foreignId('aggregator_listing_id')
                ->nullable()
                ->after('external_id')
                ->constrained('aggregator_listings')
                ->nullOnDelete();

            $table->index(['tenant_id', 'aggregator_listing_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'aggregator_listing_id']);
            $table->dropConstrainedForeignId('aggregator_listing_id');
        });
    }
};
