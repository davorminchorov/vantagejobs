<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parser_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // Target parser_version that every processed row is bumped to.
            // Pinned at command start so resumes keep landing on the same target.
            $table->unsignedInteger('target_version');

            // Resumable bookkeeping: the command processes ids in [from_id, to_id]
            // in chunks. `from_id` is the watermark for resuming via --from=ID.
            // `to_id` is null while the run is in flight and gets set on
            // completion.
            $table->unsignedBigInteger('from_id')->default(1);
            $table->unsignedBigInteger('to_id')->nullable();
            $table->unsignedInteger('processed')->default(0);

            // When the user passes --force-corrections we record it so the
            // parser_runs row is a faithful log of the invocation.
            $table->boolean('force_corrections')->default(false);

            // Source-filter applied during this run (`hackernews`, `remotive`, ...)
            // or null for "all sources".
            $table->string('source_filter', 32)->nullable();

            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();

            $table->index(['tenant_id', 'started_at']);
            $table->index(['tenant_id', 'target_version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parser_runs');
    }
};
