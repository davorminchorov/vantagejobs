<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a funded company does: the feed's one-line summary, its raw category
 * tags, and the industries / software label derived from them by
 * App\Signals\Funding\IndustryClassifier.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('funding_announcements', function (Blueprint $table): void {
            $table->text('summary')->nullable()->after('url');
            $table->jsonb('categories')->nullable()->after('summary');
            $table->jsonb('industries')->nullable()->after('categories');
            // true = software, false = hardware / deep tech, null = unclear.
            $table->boolean('is_software')->nullable()->after('industries');
        });
    }

    public function down(): void
    {
        Schema::table('funding_announcements', function (Blueprint $table): void {
            $table->dropColumn(['summary', 'categories', 'industries', 'is_software']);
        });
    }
};
