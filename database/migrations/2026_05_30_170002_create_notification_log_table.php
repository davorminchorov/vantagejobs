<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_log', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // NotificationKind value.
            $table->string('kind');

            // Set for A-tier and source-health entries so the throttle/dedupe
            // sweep can look up the last send per company.
            $table->unsignedBigInteger('company_id')->nullable();

            $table->string('recipient');
            $table->string('subject')->nullable();
            $table->jsonb('context')->nullable();

            $table->timestampTz('sent_at');

            // Append-only ledger — created_at only, no updated_at.
            $table->timestampTz('created_at')->useCurrent();

            $table->index('company_id');
            $table->index(['tenant_id', 'kind', 'company_id', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_log');
    }
};
