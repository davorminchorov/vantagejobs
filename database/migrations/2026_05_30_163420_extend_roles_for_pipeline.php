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
            $table->string('pipeline_status')->nullable()->after('is_open');
            $table->string('application_source')->nullable()->after('pipeline_status');
            $table->timestampTz('applied_at')->nullable()->after('application_source');

            // Cross-table soft reference to contacts. No FK constraint to avoid
            // migration-ordering coupling; resolved in handlers.
            $table->unsignedBigInteger('referral_contact_id')->nullable()->after('applied_at');

            $table->string('next_action')->nullable()->after('referral_contact_id');
            $table->date('next_action_date')->nullable()->after('next_action');
            $table->text('general_notes')->nullable()->after('next_action_date');
            $table->text('interview_prep')->nullable()->after('general_notes');

            $table->index(['tenant_id', 'pipeline_status']);
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'pipeline_status']);
            $table->dropColumn([
                'pipeline_status',
                'application_source',
                'applied_at',
                'referral_contact_id',
                'next_action',
                'next_action_date',
                'general_notes',
                'interview_prep',
            ]);
        });
    }
};
