<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('role_id')->nullable()->constrained('roles')->nullOnDelete();

            $table->string('type');
            $table->string('title');
            $table->text('body')->nullable();
            $table->timestampTz('occurred_at');

            $table->timestampTz('created_at')->useCurrent();

            $table->index(['tenant_id', 'company_id', 'occurred_at']);
            $table->index(['tenant_id', 'type', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
