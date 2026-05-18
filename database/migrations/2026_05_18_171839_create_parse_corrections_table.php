<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parse_corrections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('listing_id')->constrained('aggregator_listings')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // ParsedListing field key (company_name, role_title, seniority, etc.).
            $table->string('field');

            // Original parser output (may be null when the parser produced MISSING).
            $table->jsonb('old_value')->nullable();
            $table->decimal('old_confidence', 4, 3)->nullable();
            $table->string('old_source', 16)->nullable();

            // User-supplied value.
            $table->jsonb('new_value');

            $table->timestampTz('created_at')->useCurrent();

            $table->index(['tenant_id', 'listing_id']);
            $table->index(['tenant_id', 'field', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parse_corrections');
    }
};
