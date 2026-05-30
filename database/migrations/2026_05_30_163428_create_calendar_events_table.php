<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // Nullable polymorphic owner (morph-map key role/company/contact),
            // resolved manually in handlers. Nullable allows standalone reminders.
            $table->string('owner_type')->nullable();
            $table->unsignedBigInteger('owner_id')->nullable();

            $table->string('title');

            // Canonical UTC instant plus the original IANA zone for faithful
            // iCal TZID output.
            $table->timestampTz('starts_at');
            $table->string('timezone');

            $table->string('type');
            $table->text('notes')->nullable();
            $table->string('url')->nullable();

            $table->unsignedInteger('reminder_minutes')->nullable()->default(30);
            $table->timestampTz('reminder_sent_at')->nullable();

            $table->timestampsTz();

            $table->index(['tenant_id', 'starts_at']);
            $table->index(['tenant_id', 'owner_type', 'owner_id']);
            $table->index(['tenant_id', 'reminder_sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_events');
    }
};
