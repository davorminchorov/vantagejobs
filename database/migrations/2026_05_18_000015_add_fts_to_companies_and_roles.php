<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Postgres-only feature. Dev/CI runs SQLite per phpunit.xml; production is PG.
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Generated tsvector columns + GIN indexes for Cmd-K search.
        DB::statement(<<<'SQL'
            ALTER TABLE companies
            ADD COLUMN search tsvector
            GENERATED ALWAYS AS (
                to_tsvector('english',
                    coalesce(name, '') || ' ' ||
                    coalesce(domain, '') || ' ' ||
                    coalesce(ats_slug, '')
                )
            ) STORED
        SQL);

        DB::statement('CREATE INDEX companies_search_idx ON companies USING GIN (search)');

        DB::statement(<<<'SQL'
            ALTER TABLE roles
            ADD COLUMN search tsvector
            GENERATED ALWAYS AS (
                to_tsvector('english',
                    coalesce(title, '') || ' ' ||
                    coalesce(location, '') || ' ' ||
                    coalesce(team, '') || ' ' ||
                    coalesce(department, '')
                )
            ) STORED
        SQL);

        DB::statement('CREATE INDEX roles_search_idx ON roles USING GIN (search)');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS roles_search_idx');
        Schema::table('roles', function ($table): void {
            $table->dropColumn('search');
        });

        DB::statement('DROP INDEX IF EXISTS companies_search_idx');
        Schema::table('companies', function ($table): void {
            $table->dropColumn('search');
        });
    }
};
