<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_views', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            // 'companies' | 'roles' | 'discover' — kept as a free string so
            // future entities can be added without a migration.
            $table->string('entity');
            // Filter payload — canonical wire shape matching the
            // {Entity}ListFilters::toArray() output. Unknown keys are dropped
            // at read time by the filter value object.
            $table->jsonb('filters')->default('{}');
            $table->integer('sort_order')->default(0);
            $table->boolean('is_default')->default(false);
            $table->timestampsTz();

            $table->index(['tenant_id', 'user_id', 'entity', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_views');
    }
};
