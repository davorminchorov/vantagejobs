<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dismissed_companies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // Aggressively normalized key — same shape as
            // aggregator_listings.normalized_company_key.
            $table->string('normalized_company_key');
            $table->string('display_name');

            $table->unsignedInteger('dismiss_count')->default(0);
            // Flips true automatically on the 3rd manual dismissal, or set manually.
            $table->boolean('auto_dismiss')->default(false);

            $table->timestampTz('first_dismissed_at')->nullable();
            $table->timestampTz('last_dismissed_at')->nullable();

            $table->timestampsTz();

            $table->unique(['tenant_id', 'normalized_company_key']);
            $table->index(['tenant_id', 'auto_dismiss']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dismissed_companies');
    }
};
