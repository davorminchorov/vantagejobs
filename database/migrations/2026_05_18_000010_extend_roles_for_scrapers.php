<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->string('workplace_type')->default('unspecified')->after('is_remote');
            $table->string('department')->nullable()->after('workplace_type');
            $table->string('team')->nullable()->after('department');

            $table->unsignedBigInteger('comp_min')->nullable()->after('team');
            $table->unsignedBigInteger('comp_max')->nullable()->after('comp_min');
            $table->string('comp_currency', 3)->nullable()->after('comp_max');
            $table->string('comp_interval')->nullable()->after('comp_currency');

            $table->jsonb('raw')->nullable()->after('comp_interval');

            $table->index(['tenant_id', 'workplace_type']);
        });

        // url can be long for some ATS deep-links — widen to TEXT.
        Schema::table('roles', function (Blueprint $table): void {
            $table->text('url')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'workplace_type']);
            $table->dropColumn([
                'workplace_type',
                'department',
                'team',
                'comp_min',
                'comp_max',
                'comp_currency',
                'comp_interval',
                'raw',
            ]);
        });

        Schema::table('roles', function (Blueprint $table): void {
            $table->string('url')->nullable()->change();
        });
    }
};
