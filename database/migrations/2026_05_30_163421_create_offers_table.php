<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('role_id')->unique()->constrained('roles')->cascadeOnDelete();

            $table->string('currency', 3);

            $table->unsignedBigInteger('base')->nullable();
            $table->unsignedBigInteger('bonus')->nullable();
            $table->unsignedBigInteger('signing_bonus')->nullable();

            $table->unsignedBigInteger('equity_shares')->nullable();
            $table->decimal('equity_strike', 12, 4)->nullable();
            $table->decimal('equity_assumed_price', 12, 4)->nullable();
            $table->unsignedSmallInteger('vesting_years')->nullable();

            // Stored snapshot, recomputed on save.
            $table->unsignedBigInteger('computed_total_comp_usd')->nullable();

            $table->date('decision_deadline')->nullable();
            $table->text('notes')->nullable();

            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offers');
    }
};
