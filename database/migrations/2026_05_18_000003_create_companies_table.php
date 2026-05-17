<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            $table->string('name');
            $table->string('website')->nullable();
            $table->string('careers_url')->nullable();
            $table->string('domain')->nullable();

            $table->string('ats_type')->nullable();
            $table->string('ats_slug')->nullable();

            $table->string('tier')->default('C');
            $table->string('status')->default('researching');

            $table->timestampTz('last_checked_at')->nullable();

            $table->timestampsTz();

            $table->unique(['tenant_id', 'domain']);
            $table->index(['tenant_id', 'tier']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'last_checked_at']);
            $table->index(['tenant_id', 'ats_type', 'ats_slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
