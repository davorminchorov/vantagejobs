<?php

declare(strict_types=1);

namespace Tests\Unit\Tenancy;

use App\Tenancy\RequiresTenantContextException;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

final class TenantContextTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function current_returns_null_when_unset(): void
    {
        $context = new TenantContext();

        $this->assertNull($context->current());
        $this->assertFalse($context->has());
    }

    #[Test]
    public function currentOrFail_throws_when_unset(): void
    {
        $context = new TenantContext();

        $this->expectException(RequiresTenantContextException::class);

        $context->currentOrFail();
    }

    #[Test]
    public function setCurrent_then_current_returns_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $context = new TenantContext();

        $context->setCurrent($tenant);

        $this->assertTrue($context->has());
        $this->assertSame($tenant->id, $context->current()?->id);
        $this->assertSame($tenant->id, $context->currentOrFail()->id);
    }

    #[Test]
    public function withTenant_runs_callback_inside_context_and_restores_previous(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();
        $context = new TenantContext();
        $context->setCurrent($a);

        $inside = null;
        $result = $context->withTenant($b, function () use ($context, &$inside) {
            $inside = $context->current()?->id;

            return 'ok';
        });

        $this->assertSame($b->id, $inside);
        $this->assertSame('ok', $result);
        $this->assertSame($a->id, $context->current()?->id);
    }

    #[Test]
    public function withTenant_restores_previous_even_on_exception(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();
        $context = new TenantContext();
        $context->setCurrent($a);

        try {
            $context->withTenant($b, function (): void {
                throw new RuntimeException('boom');
            });
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame($a->id, $context->current()?->id);
    }
}
