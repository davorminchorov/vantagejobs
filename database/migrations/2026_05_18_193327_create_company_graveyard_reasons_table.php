<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_graveyard_reasons', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('demoted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('restored_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('demoted_at');
            $table->timestampTz('restored_at')->nullable();
            $table->text('reason')->nullable();
            $table->jsonb('tags')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'company_id', 'demoted_at'], 'graveyard_reasons_tenant_company_idx');
            $table->index(['tenant_id', 'demoted_at'], 'graveyard_reasons_tenant_demoted_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_graveyard_reasons');
    }
};
