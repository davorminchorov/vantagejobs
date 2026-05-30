<?php

declare(strict_types=1);

namespace Tests\Feature\FollowUps;

use App\Enums\PipelineStatus;
use App\FollowUps\DueFollowUpsQuery;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Role;
use App\Models\User;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class FollowUpQueueTest extends TestCase
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
    public function unauthenticated_users_are_redirected_to_login(): void
    {
        $this->get('/follow-ups')->assertRedirect('/login');
    }

    #[Test]
    public function page_renders_with_threshold_and_contacts_props(): void
    {
        $this->actingAs($this->user)
            ->get('/follow-ups')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('FollowUps/Index')
                ->where('threshold_days', 7)
                ->has('contacts'));
    }

    #[Test]
    public function stale_contact_at_an_active_company_appears_in_the_queue(): void
    {
        $company = $this->companyWithRole(PipelineStatus::APPLIED);
        Contact::factory()
            ->touchedDaysAgo(30)
            ->create(['company_id' => $company->id, 'name' => 'Overdue Olive']);

        $due = $this->query()->get();

        $this->assertCount(1, $due);
        $this->assertSame('Overdue Olive', $due->first()->name);
    }

    #[Test]
    public function screen_stage_also_qualifies_the_company(): void
    {
        $company = $this->companyWithRole(PipelineStatus::SCREEN);
        Contact::factory()->stale()->create(['company_id' => $company->id]);

        $this->assertSame(1, $this->query()->count());
    }

    #[Test]
    public function recently_touched_contact_is_excluded(): void
    {
        $company = $this->companyWithRole(PipelineStatus::APPLIED);
        Contact::factory()
            ->touchedDaysAgo(2)
            ->create(['company_id' => $company->id]);

        $this->assertSame(0, $this->query()->count());
    }

    #[Test]
    public function never_touched_contact_is_included_and_sorts_first(): void
    {
        $company = $this->companyWithRole(PipelineStatus::APPLIED);
        Contact::factory()
            ->touchedDaysAgo(40)
            ->create(['company_id' => $company->id, 'name' => 'Touched Tom']);
        Contact::factory()
            ->create(['company_id' => $company->id, 'name' => 'Never Nora', 'last_touch' => null]);

        $due = $this->query()->get();

        $this->assertCount(2, $due);
        $this->assertSame('Never Nora', $due->first()->name);
    }

    #[Test]
    public function older_touches_sort_before_newer_ones(): void
    {
        $company = $this->companyWithRole(PipelineStatus::APPLIED);
        Contact::factory()
            ->touchedDaysAgo(15)
            ->create(['company_id' => $company->id, 'name' => 'Newer Ned']);
        Contact::factory()
            ->touchedDaysAgo(40)
            ->create(['company_id' => $company->id, 'name' => 'Older Oprah']);

        $names = $this->query()->get()->pluck('name')->all();

        $this->assertSame(['Older Oprah', 'Newer Ned'], $names);
    }

    #[Test]
    public function contact_at_company_without_active_role_is_excluded(): void
    {
        $company = $this->companyWithRole(PipelineStatus::RESEARCHING);
        Contact::factory()->stale()->create(['company_id' => $company->id]);

        // A second company with an offer-stage role also should not count.
        $offerCompany = $this->companyWithRole(PipelineStatus::OFFER);
        Contact::factory()->stale()->create(['company_id' => $offerCompany->id]);

        $this->assertSame(0, $this->query()->count());
    }

    #[Test]
    public function contact_with_no_company_is_excluded(): void
    {
        Contact::factory()
            ->withoutCompany()
            ->stale()
            ->create();

        $this->assertSame(0, $this->query()->count());
    }

    #[Test]
    public function company_with_no_pipeline_role_is_excluded(): void
    {
        $company = Company::factory()->create();
        // A scraped role the user never opted into the pipeline.
        Role::factory()->create(['company_id' => $company->id, 'pipeline_status' => null]);
        Contact::factory()->stale()->create(['company_id' => $company->id]);

        $this->assertSame(0, $this->query()->count());
    }

    #[Test]
    public function queue_is_scoped_to_the_current_tenant(): void
    {
        // Build a fully-qualifying contact in another tenant.
        $otherTenant = Tenant::factory()->create();
        app(TenantContext::class)->withTenant($otherTenant, function (): void {
            $company = $this->companyWithRole(PipelineStatus::APPLIED);
            Contact::factory()->stale()->create(['company_id' => $company->id]);
        });

        // Current tenant has its own qualifying contact.
        $company = $this->companyWithRole(PipelineStatus::APPLIED);
        Contact::factory()->stale()->create(['company_id' => $company->id, 'name' => 'Mine']);

        $due = $this->query()->get();

        $this->assertCount(1, $due);
        $this->assertSame('Mine', $due->first()->name);
    }

    #[Test]
    public function get_respects_an_explicit_limit(): void
    {
        $company = $this->companyWithRole(PipelineStatus::APPLIED);
        Contact::factory()->count(3)->stale()->create(['company_id' => $company->id]);

        $this->assertCount(2, $this->query()->get(2));
    }

    #[Test]
    public function threshold_boundary_uses_the_configured_window(): void
    {
        config()->set('pipeline.follow_up_threshold_days', 10);
        $company = $this->companyWithRole(PipelineStatus::APPLIED);

        // Touched 8 days ago: inside the 10-day window, so not due.
        Contact::factory()
            ->touchedDaysAgo(8)
            ->create(['company_id' => $company->id]);
        // Touched 12 days ago: outside the window, so due.
        Contact::factory()
            ->touchedDaysAgo(12)
            ->create(['company_id' => $company->id, 'name' => 'Way Overdue']);

        $due = $this->query()->get();

        $this->assertCount(1, $due);
        $this->assertSame('Way Overdue', $due->first()->name);
    }

    private function companyWithRole(PipelineStatus $status): Company
    {
        $company = Company::factory()->create();
        Role::factory()->create([
            'company_id' => $company->id,
            'pipeline_status' => $status,
            'applied_at' => CarbonImmutable::now()->subDays(40),
        ]);

        return $company;
    }

    private function query(): DueFollowUpsQuery
    {
        return app(DueFollowUpsQuery::class);
    }
}
