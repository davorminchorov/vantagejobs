<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aggregator_listings', function (Blueprint $table): void {
            // Bumped on every full re-parse run. Used to skip already-current rows.
            $table->unsignedInteger('parser_version')->default(1)->after('parsed');
        });

        // Widen columns that legitimately overflow on HN's noisier postings.
        // Role titles and company names sourced from prose first-lines can run long;
        // their derived normalized keys ride along.
        Schema::table('aggregator_listings', function (Blueprint $table): void {
            $table->text('parsed_company_name')->nullable()->change();
            $table->text('parsed_role_title')->nullable()->change();
            $table->text('normalized_company_key')->nullable()->change();
            $table->text('normalized_role_key')->nullable()->change();
            $table->text('location_bucket')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('aggregator_listings', function (Blueprint $table): void {
            $table->dropColumn('parser_version');
        });

        Schema::table('aggregator_listings', function (Blueprint $table): void {
            $table->string('parsed_company_name')->nullable()->change();
            $table->string('parsed_role_title')->nullable()->change();
            $table->string('normalized_company_key')->nullable()->change();
            $table->string('normalized_role_key')->nullable()->change();
            $table->string('location_bucket')->nullable()->change();
        });
    }
};
