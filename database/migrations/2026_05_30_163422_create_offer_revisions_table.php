<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offer_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('offer_id')->constrained('offers')->cascadeOnDelete();

            // Full package at revision time.
            $table->jsonb('snapshot');
            $table->unsignedBigInteger('computed_total_comp_usd')->nullable();

            // Append-only — created_at only, no updated_at.
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['tenant_id', 'offer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offer_revisions');
    }
};
