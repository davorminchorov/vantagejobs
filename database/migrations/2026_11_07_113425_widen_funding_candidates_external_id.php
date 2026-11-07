<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Google News RSS guids are opaque article tokens of roughly 260
 * characters, longer than the original varchar(255). Every insert from
 * signals:funding:news failed, so the review queue stayed empty.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('funding_candidates', function (Blueprint $table): void {
            $table->text('external_id')->change();
        });
    }

    public function down(): void
    {
        Schema::table('funding_candidates', function (Blueprint $table): void {
            $table->string('external_id')->change();
        });
    }
};
