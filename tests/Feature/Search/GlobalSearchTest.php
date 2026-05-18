<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class GlobalSearchTest extends TestCase
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
    }

    #[Test]
    public function empty_query_returns_empty_groups(): void
    {
        $this->actingAs($this->user)
            ->getJson('/search?q=')
            ->assertOk()
            ->assertExactJson(['companies' => [], 'roles' => []]);
    }

    #[Test]
    public function single_character_query_returns_empty_groups(): void
    {
        Company::factory()->create(['name' => 'Acme']);

        $this->actingAs($this->user)
            ->getJson('/search?q=a')
            ->assertOk()
            ->assertJsonPath('companies', [])
            ->assertJsonPath('roles', []);
    }

    #[Test]
    public function matches_company_by_name(): void
    {
        Company::factory()->create(['name' => 'Acme Robotics', 'domain' => 'acme.test']);
        Company::factory()->create(['name' => 'Globex Industries', 'domain' => 'globex.test']);

        $response = $this->actingAs($this->user)->getJson('/search?q=robot');
        $response->assertOk();
        $this->assertCount(1, $response->json('companies'));
        $this->assertSame('Acme Robotics', $response->json('companies.0.name'));
        $this->assertSame('/companies/'.$response->json('companies.0.id'), $response->json('companies.0.url'));
    }

    #[Test]
    public function prefix_matching_works_on_partial_words(): void
    {
        Company::factory()->create(['name' => 'Stripe Inc', 'domain' => 'stripe.com']);
        Company::factory()->create(['name' => 'Other Co', 'domain' => 'other.com']);

        $response = $this->actingAs($this->user)->getJson('/search?q=stri');
        $response->assertOk();
        $this->assertSame('Stripe Inc', $response->json('companies.0.name'));
    }

    #[Test]
    public function matches_role_by_title_and_carries_company_name(): void
    {
        $company = Company::factory()->create(['name' => 'Acme']);
        Role::factory()->create([
            'company_id' => $company->id,
            'title' => 'Staff Platform Engineer',
            'team' => 'Infrastructure',
        ]);

        $response = $this->actingAs($this->user)->getJson('/search?q=platform');
        $response->assertOk();
        $this->assertSame('Staff Platform Engineer', $response->json('roles.0.title'));
        $this->assertSame('Acme', $response->json('roles.0.company_name'));
        $this->assertSame('/companies/'.$company->id, $response->json('roles.0.url'));
    }

    #[Test]
    public function tenant_scoping_hides_foreign_tenants_hits(): void
    {
        Company::factory()->create(['name' => 'Mine Robotics']);

        $otherTenant = Tenant::factory()->create();
        app(TenantContext::class)->withTenant($otherTenant, function (): void {
            Company::factory()->create(['name' => 'Theirs Robotics']);
        });

        $response = $this->actingAs($this->user)->getJson('/search?q=robot');
        $response->assertOk();
        $names = collect($response->json('companies'))->pluck('name');
        $this->assertCount(1, $names);
        $this->assertSame('Mine Robotics', $names->first());
    }

    #[Test]
    public function results_are_capped_at_eight_per_group(): void
    {
        Company::factory()->count(12)->create(['name' => 'Engineering Co']);

        $response = $this->actingAs($this->user)->getJson('/search?q=engineering');
        $response->assertOk();
        $this->assertCount(8, $response->json('companies'));
    }

    #[Test]
    public function multi_token_query_requires_all_tokens(): void
    {
        Company::factory()->create(['name' => 'Acme Robotics']);
        Company::factory()->create(['name' => 'Acme Foods']);

        $response = $this->actingAs($this->user)->getJson('/search?q=acme+robot');
        $response->assertOk();
        $this->assertCount(1, $response->json('companies'));
        $this->assertSame('Acme Robotics', $response->json('companies.0.name'));
    }

    #[Test]
    public function malformed_query_with_special_chars_does_not_throw(): void
    {
        Company::factory()->create(['name' => 'Acme']);

        $this->actingAs($this->user)
            ->getJson('/search?q='.urlencode('!!! @@ ##'))
            ->assertOk()
            ->assertExactJson(['companies' => [], 'roles' => []]);
    }
}
