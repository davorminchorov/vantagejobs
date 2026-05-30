<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();

            $table->string('name');
            $table->string('role_title')->nullable();
            $table->string('linkedin_url')->nullable();
            $table->string('email')->nullable();
            $table->text('notes')->nullable();
            $table->timestampTz('last_touch')->nullable();

            $table->timestampsTz();

            $table->index(['tenant_id', 'company_id']);
            $table->index(['tenant_id', 'last_touch']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
