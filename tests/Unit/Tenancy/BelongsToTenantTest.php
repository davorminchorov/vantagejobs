<?php

declare(strict_types=1);

namespace Tests\Unit\Tenancy;

use App\Tenancy\RequiresTenantContextException;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\Tenancy\TenantScopedFixtureModel;
use Tests\TestCase;

final class BelongsToTenantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('tenant_scoped_fixtures', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('name');
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('tenant_scoped_fixtures');
        app(TenantContext::class)->setCurrent(null);

        parent::tearDown();
    }

    #[Test]
    public function creating_a_model_auto_fills_tenant_id_from_context(): void
    {
        $tenant = Tenant::factory()->create();
        app(TenantContext::class)->setCurrent($tenant);

        $model = TenantScopedFixtureModel::query()->create(['name' => 'auto']);

        $this->assertSame($tenant->id, $model->tenant_id);
    }

    #[Test]
    public function creating_a_model_without_tenant_context_throws(): void
    {
        app(TenantContext::class)->setCurrent(null);

        $this->expectException(RequiresTenantContextException::class);

        TenantScopedFixtureModel::query()->create(['name' => 'orphan']);
    }

    #[Test]
    public function global_scope_filters_queries_to_current_tenant(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();
        $context = app(TenantContext::class);

        $context->setCurrent($a);
        TenantScopedFixtureModel::query()->create(['name' => 'a1']);
        TenantScopedFixtureModel::query()->create(['name' => 'a2']);

        $context->setCurrent($b);
        TenantScopedFixtureModel::query()->create(['name' => 'b1']);

        $context->setCurrent($a);
        $rows = TenantScopedFixtureModel::query()->get();

        $this->assertCount(2, $rows);
        $this->assertEqualsCanonicalizing(['a1', 'a2'], $rows->pluck('name')->all());
    }

    #[Test]
    public function global_scope_is_noop_when_no_tenant_set(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();
        $context = app(TenantContext::class);

        $context->setCurrent($a);
        TenantScopedFixtureModel::query()->create(['name' => 'a1']);
        $context->setCurrent($b);
        TenantScopedFixtureModel::query()->create(['name' => 'b1']);

        $context->setCurrent(null);
        $rows = TenantScopedFixtureModel::query()->get();

        $this->assertCount(2, $rows);
    }

    #[Test]
    public function withoutTenantScope_returns_all_rows(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();
        $context = app(TenantContext::class);

        $context->setCurrent($a);
        TenantScopedFixtureModel::query()->create(['name' => 'a1']);
        $context->setCurrent($b);
        TenantScopedFixtureModel::query()->create(['name' => 'b1']);

        $context->setCurrent($a);
        $rows = TenantScopedFixtureModel::withoutTenantScope()->get();

        $this->assertCount(2, $rows);
        $this->assertEqualsCanonicalizing(['a1', 'b1'], $rows->pluck('name')->all());
    }
}
