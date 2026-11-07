<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The Ashby scraper now keeps job descriptions and reads the posting API's
 * `location` / `department` / `team` keys. Ashby boards answer 304 Not
 * Modified while the stored ETag still matches, so existing roles would
 * never be refreshed. Clearing the conditional-fetch headers once forces
 * the next scrape of each Ashby company to fetch and re-save its roles.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('companies')
            ->where('ats_type', 'ashby')
            ->update(['etag' => null, 'last_modified' => null]);
    }

    public function down(): void
    {
        // Nothing to restore: the next scrape stores fresh headers.
    }
};
