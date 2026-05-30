<?php

declare(strict_types=1);

namespace Tests\Feature\Pipeline\Offers;

use App\Enums\PipelineStatus;
use App\Http\Controllers\Pipeline\Offers\ComparisonController;
use App\Models\Offer;
use App\Models\Role;
use App\Models\User;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class OfferComparisonTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->setCurrent($this->tenant);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);

        Route::middleware(['web', 'auth'])->group(function (): void {
            Route::get('/pipeline/offers/comparison', ComparisonController::class)
                ->name('pipeline.offers.comparison');
        });
    }

    #[Test]
    public function it_lists_only_offers_for_roles_at_the_offer_stage_ranked_by_total_comp(): void
    {
        $offerRoleLow = Role::factory()->create(['pipeline_status' => PipelineStatus::OFFER->value]);
        $offerRoleHigh = Role::factory()->create(['pipeline_status' => PipelineStatus::OFFER->value]);
        $screenRole = Role::factory()->create(['pipeline_status' => PipelineStatus::SCREEN->value]);

        Offer::factory()->create(['role_id' => $offerRoleLow->id, 'computed_total_comp_usd' => 150_000]);
        Offer::factory()->create(['role_id' => $offerRoleHigh->id, 'computed_total_comp_usd' => 250_000]);
        Offer::factory()->create(['role_id' => $screenRole->id, 'computed_total_comp_usd' => 999_000]);

        $response = $this->actingAs($this->user)
            ->getJson('/pipeline/offers/comparison')
            ->assertOk();

        $response->assertJsonCount(2, 'offers');
        $response->assertJsonPath('count', 2);
        $response->assertJsonPath('offers.0.computed_total_comp_usd', 250_000);
        $response->assertJsonPath('offers.1.computed_total_comp_usd', 150_000);
        $response->assertJsonPath('best_offer_id', Offer::query()->where('role_id', $offerRoleHigh->id)->value('id'));
    }

    #[Test]
    public function it_returns_an_empty_set_when_no_role_is_at_offer(): void
    {
        $role = Role::factory()->create(['pipeline_status' => PipelineStatus::SCREEN->value]);
        Offer::factory()->create(['role_id' => $role->id, 'computed_total_comp_usd' => 200_000]);

        $this->actingAs($this->user)
            ->getJson('/pipeline/offers/comparison')
            ->assertOk()
            ->assertJsonPath('count', 0)
            ->assertJsonPath('best_offer_id', null);
    }

    #[Test]
    public function it_does_not_leak_offers_from_other_tenants(): void
    {
        $otherTenant = Tenant::factory()->create();
        app(TenantContext::class)->setCurrent($otherTenant);
        $otherRole = Role::factory()->create(['pipeline_status' => PipelineStatus::OFFER->value]);
        Offer::factory()->create(['role_id' => $otherRole->id, 'computed_total_comp_usd' => 300_000]);
        app(TenantContext::class)->setCurrent($this->tenant);

        $myRole = Role::factory()->create(['pipeline_status' => PipelineStatus::OFFER->value]);
        Offer::factory()->create(['role_id' => $myRole->id, 'computed_total_comp_usd' => 180_000]);

        $this->actingAs($this->user)
            ->getJson('/pipeline/offers/comparison')
            ->assertOk()
            ->assertJsonCount(1, 'offers')
            ->assertJsonPath('offers.0.computed_total_comp_usd', 180_000);
    }

    #[Test]
    public function guests_cannot_view_the_comparison(): void
    {
        $this->getJson('/pipeline/offers/comparison')->assertUnauthorized();
    }
}
