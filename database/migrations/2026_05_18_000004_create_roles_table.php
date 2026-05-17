<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('source');
            $table->string('external_id');

            $table->string('title');
            $table->string('url')->nullable();
            $table->string('location')->nullable();
            $table->boolean('is_remote')->nullable();
            $table->boolean('is_open')->default(true);

            $table->timestampTz('posted_at')->nullable();
            $table->timestampTz('last_seen_at')->nullable();

            $table->timestampsTz();

            $table->unique(['tenant_id', 'source', 'external_id']);
            $table->index(['tenant_id', 'company_id']);
            $table->index(['tenant_id', 'is_open']);
            $table->index(['tenant_id', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
