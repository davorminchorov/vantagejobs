<?php

declare(strict_types=1);

namespace Tests\Feature\Roles;

use App\Models\AggregatorListing;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class RoleActionsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::factory()->create();
        app(TenantContext::class)->setCurrent($tenant);
        $this->user = User::factory()->create(['tenant_id' => $tenant->id]);
    }

    #[Test]
    public function dismiss_sets_dismissed_at_and_redirects_back(): void
    {
        $role = Role::factory()->create(['company_id' => Company::factory()->create()->id]);

        $this->actingAs($this->user)
            ->from('/roles')
            ->post(route('roles.dismiss', $role->id))
            ->assertRedirect('/roles');

        $this->assertNotNull($role->refresh()->dismissed_at);
    }

    #[Test]
    public function restore_clears_dismissed_at(): void
    {
        $role = Role::factory()->create([
            'company_id' => Company::factory()->create()->id,
            'dismissed_at' => now(),
        ]);

        $this->actingAs($this->user)
            ->from('/roles?status=dismissed')
            ->post(route('roles.restore', $role->id))
            ->assertRedirect('/roles?status=dismissed');

        $this->assertNull($role->refresh()->dismissed_at);
    }

    #[Test]
    public function dismiss_and_restore_return_404_for_unknown_roles(): void
    {
        $this->actingAs($this->user)->post(route('roles.dismiss', 999999))->assertNotFound();
        $this->actingAs($this->user)->post(route('roles.restore', 999999))->assertNotFound();
    }

    #[Test]
    public function description_endpoint_returns_body_text(): void
    {
        $role = Role::factory()->create([
            'company_id' => Company::factory()->create()->id,
            'source' => 'teamtailor',
            'raw' => ['description' => '<p>Build things.</p><ul><li>Ship</li></ul>'],
        ]);

        $this->actingAs($this->user)
            ->getJson(route('roles.description', $role->id))
            ->assertOk()
            ->assertExactJson(['body_text' => "Build things.\n\n- Ship"]);
    }

    #[Test]
    public function description_endpoint_returns_null_when_source_stores_nothing(): void
    {
        $role = Role::factory()->create([
            'company_id' => Company::factory()->create()->id,
            'source' => 'workday',
            'raw' => ['title' => 'x'],
        ]);

        $this->actingAs($this->user)
            ->getJson(route('roles.description', $role->id))
            ->assertOk()
            ->assertExactJson(['body_text' => null]);
    }

    #[Test]
    public function description_endpoint_uses_linked_aggregator_listing(): void
    {
        $listing = AggregatorListing::factory()->create();
        $role = Role::factory()->create([
            'company_id' => Company::factory()->create()->id,
            'source' => 'aggregator:arbeitnow',
            'aggregator_listing_id' => $listing->id,
        ]);

        $response = $this->actingAs($this->user)->getJson(route('roles.description', $role->id));

        $response->assertOk()->assertJsonStructure(['body_text']);
    }

    #[Test]
    public function description_endpoint_404s_for_unknown_role(): void
    {
        $this->actingAs($this->user)->getJson(route('roles.description', 999999))->assertNotFound();
    }

    #[Test]
    public function endpoints_require_authentication(): void
    {
        $role = Role::factory()->create(['company_id' => Company::factory()->create()->id]);

        $this->get(route('roles.index'))->assertRedirect();
        $this->getJson(route('roles.description', $role->id))->assertUnauthorized();
        $this->post(route('roles.dismiss', $role->id))->assertRedirect();
        $this->post(route('roles.restore', $role->id))->assertRedirect();
        $this->assertNull($role->refresh()->dismissed_at);
    }
}
