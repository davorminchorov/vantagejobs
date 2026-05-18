<?php

declare(strict_types=1);

namespace Tests\Feature\Companies;

use App\Enums\EventType;
use App\Models\Company;
use App\Models\CompanyGraveyardReason;
use App\Models\Event;
use App\Models\User;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class GraveyardCompanyTest extends TestCase
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
    public function demote_marks_company_inactive_and_logs_event(): void
    {
        $company = Company::factory()->create(['is_active' => true, 'status' => 'researching']);

        $this->actingAs($this->user)
            ->post("/companies/{$company->id}/graveyard", [
                'reason' => 'No relevant roles for 6 months.',
                'tags' => ['not_hiring_anymore', 'wrong_stack'],
            ])
            ->assertRedirect("/companies/{$company->id}");

        $company->refresh();
        $this->assertFalse((bool) $company->is_active);
        $this->assertSame('graveyard', $company->status);

        $reason = CompanyGraveyardReason::query()->where('company_id', $company->id)->first();
        $this->assertNotNull($reason);
        $this->assertSame('No relevant roles for 6 months.', $reason->reason);
        $this->assertEqualsCanonicalizing(['not_hiring_anymore', 'wrong_stack'], $reason->tags);
        $this->assertSame($this->user->id, $reason->demoted_by_user_id);

        $this->assertSame(1, Event::query()
            ->where('company_id', $company->id)
            ->where('type', EventType::GRAVEYARDED->value)
            ->count());
    }

    #[Test]
    public function restore_reactivates_company_and_logs_restored_event(): void
    {
        $company = Company::factory()->create(['is_active' => false, 'status' => 'graveyard', 'tier' => 'graveyard']);
        CompanyGraveyardReason::factory()->create(['company_id' => $company->id]);

        $this->actingAs($this->user)
            ->post("/companies/{$company->id}/restore")
            ->assertRedirect("/companies/{$company->id}");

        $company->refresh();
        $this->assertTrue((bool) $company->is_active);
        $this->assertSame('researching', $company->status);
        $this->assertSame('C', $company->tier);

        $reason = CompanyGraveyardReason::query()->where('company_id', $company->id)->first();
        $this->assertNotNull($reason->restored_at);
        $this->assertSame($this->user->id, $reason->restored_by_user_id);

        $this->assertSame(1, Event::query()
            ->where('company_id', $company->id)
            ->where('type', EventType::RESTORED->value)
            ->count());
    }

    #[Test]
    public function default_companies_index_excludes_graveyard_rows(): void
    {
        Company::factory()->create(['is_active' => true, 'name' => 'Alive Co']);
        Company::factory()->create(['is_active' => false, 'status' => 'graveyard', 'name' => 'Dead Co']);

        $response = $this->actingAs($this->user)->get('/companies');
        $response->assertOk();

        $names = collect($response->viewData('page')['props']['companies']['data'])
            ->pluck('name')
            ->all();
        $this->assertContains('Alive Co', $names);
        $this->assertNotContains('Dead Co', $names);
    }

    #[Test]
    public function graveyard_query_param_shows_only_graveyard_rows(): void
    {
        Company::factory()->create(['is_active' => true, 'name' => 'Alive Co']);
        Company::factory()->create(['is_active' => false, 'status' => 'graveyard', 'name' => 'Dead Co']);

        $response = $this->actingAs($this->user)->get('/companies?graveyard=1');
        $response->assertOk();

        $names = collect($response->viewData('page')['props']['companies']['data'])
            ->pluck('name')
            ->all();
        $this->assertNotContains('Alive Co', $names);
        $this->assertContains('Dead Co', $names);
    }
}
