<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            // Caches which host a Personio-hosted company actually resolves
            // on (.de vs .com) so every scrape after the first skips the
            // guaranteed-failing probe request. Cleared on ATS redetection.
            $table->string('ats_host')->nullable()->after('ats_slug');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn('ats_host');
        });
    }
};
