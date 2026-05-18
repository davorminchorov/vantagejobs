<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // taggable_type stores morph map aliases ('company', 'role'),
        // enforced via Relation::enforceMorphMap() in AppServiceProvider.
        // No tenant_id on the pivot: both endpoints (tags, companies/roles) are
        // tenant-scoped, so pivot rows are tenant-isolated transitively.
        Schema::create('taggables', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tag_id')->constrained('tags')->cascadeOnDelete();
            $table->string('taggable_type');
            $table->unsignedBigInteger('taggable_id');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['tag_id', 'taggable_type', 'taggable_id']);
            $table->index(['taggable_type', 'taggable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('taggables');
    }
};
