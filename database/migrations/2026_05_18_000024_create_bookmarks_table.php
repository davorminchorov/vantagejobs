<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookmarks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // Polymorphic target. Morph map: 'role' | 'aggregator_listing'.
            $table->string('bookmarkable_type');
            $table->unsignedBigInteger('bookmarkable_id');

            $table->text('notes')->nullable();

            // 30-day default expiry, configurable per-bookmark.
            $table->timestampTz('expires_at');
            // Set by bookmarks:expire-warned at T-3. Drives the dashboard nag.
            $table->timestampTz('expires_warned_at')->nullable();

            $table->timestampsTz();

            $table->unique(['tenant_id', 'bookmarkable_type', 'bookmarkable_id']);
            $table->index(['tenant_id', 'expires_at']);
            $table->index(['bookmarkable_type', 'bookmarkable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookmarks');
    }
};
