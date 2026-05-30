<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outreach_log_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();

            $table->string('channel');
            $table->string('subject')->nullable();
            $table->text('body')->nullable();
            $table->timestampTz('occurred_at');

            // Append-only — created_at only, no updated_at.
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['tenant_id', 'contact_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outreach_log_entries');
    }
};
