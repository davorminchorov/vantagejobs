<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_blog_posts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            // Stable identifier from the feed item: <guid> for RSS, <id> for Atom,
            // falls back to the entry link when neither is present.
            $table->string('external_id');

            $table->text('title');
            $table->text('url');
            $table->timestampTz('published_at')->nullable();
            $table->text('excerpt')->nullable();
            $table->timestampTz('fetched_at');

            $table->timestampsTz();

            $table->unique(['tenant_id', 'company_id', 'external_id'], 'company_blog_posts_external_unique');
            $table->index(
                ['tenant_id', 'company_id', 'published_at'],
                'company_blog_posts_tenant_company_published_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_blog_posts');
    }
};
