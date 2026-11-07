<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consecutive failed ATS runs that are NOT dead-board responses (5xx,
 * timeouts, 429, malformed payloads, the mass-close guard). Drives the
 * exponential backoff in scrape:all; dead-board responses keep using
 * ats_consecutive_404s and the redetect pipeline instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->unsignedSmallInteger('ats_consecutive_failures')
                ->default(0)
                ->after('ats_redetect_attempts');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn('ats_consecutive_failures');
        });
    }
};
