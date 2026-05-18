<?php

declare(strict_types=1);

namespace Tests\Feature\SavedViews;

use App\Models\Company;
use App\Models\SavedView;
use App\Models\User;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class DefaultViewTest extends TestCase
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
    public function setting_a_view_as_default_clears_the_previous_default_for_the_same_entity(): void
    {
        $a = SavedView::factory()->forEntity('companies')->default()->create(['user_id' => $this->user->id]);
        $b = SavedView::factory()->forEntity('companies')->create(['user_id' => $this->user->id]);

        $this->actingAs($this->user)
            ->from('/companies')
            ->patch("/saved-views/{$b->id}", ['is_default' => true])
            ->assertRedirect('/companies');

        $this->assertFalse($a->refresh()->is_default);
        $this->assertTrue($b->refresh()->is_default);
    }

    #[Test]
    public function defaults_for_different_entities_do_not_interfere(): void
    {
        $companies = SavedView::factory()->forEntity('companies')->default()->create(['user_id' => $this->user->id]);
        $discover = SavedView::factory()->forEntity('discover')->create(['user_id' => $this->user->id]);

        $this->actingAs($this->user)
            ->from('/companies')
            ->patch("/saved-views/{$discover->id}", ['is_default' => true])
            ->assertRedirect('/companies');

        $this->assertTrue($companies->refresh()->is_default);
        $this->assertTrue($discover->refresh()->is_default);
    }

    #[Test]
    public function default_view_is_auto_applied_to_companies_index_when_no_query_string(): void
    {
        Company::factory()->create(['tier' => 'A', 'name' => 'A Co']);
        Company::factory()->create(['tier' => 'B', 'name' => 'B Co']);

        SavedView::factory()->forEntity('companies')->default()->create([
            'user_id' => $this->user->id,
            'filters' => ['tier' => ['A']],
        ]);

        $this->actingAs($this->user)
            ->get('/companies')
            ->assertRedirect();
    }
}
