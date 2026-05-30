<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('export_jobs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // Cross-module reference stays a plain unsigned big integer.
            $table->unsignedBigInteger('user_id');

            // ExportJobStatus value.
            $table->string('status');

            // ExportFormat values requested for the bundle.
            $table->jsonb('formats');

            // Schema version + per-entity row counts + timestamp, written when
            // the bundle is produced.
            $table->jsonb('manifest')->nullable();

            $table->string('disk');
            $table->string('path')->nullable();
            $table->string('schema_version')->nullable();
            $table->text('error')->nullable();

            // End of the 24h downloadable window.
            $table->timestampTz('expires_at')->nullable();

            $table->timestampsTz();

            $table->index(['tenant_id', 'status']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('export_jobs');
    }
};
