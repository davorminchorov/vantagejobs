<?php

declare(strict_types=1);

namespace Tests\Feature\Discover;

use App\Enums\AggregatorListingStatus;
use App\Enums\AggregatorSourceType;
use App\Enums\EventType;
use App\Enums\PipelineStatus;
use App\Models\AggregatorListing;
use App\Models\AggregatorSource;
use App\Models\Company;
use App\Models\Event;
use App\Models\Role;
use App\Models\User;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AddListingToPipelineTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    private AggregatorSource $source;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->setCurrent($this->tenant);
        $this->user = User::factory()->create();
        $this->source = AggregatorSource::factory()->ofType(AggregatorSourceType::HACKERNEWS)->create();
    }

    private function listing(array $attributes = []): AggregatorListing
    {
        return AggregatorListing::factory()->create([
            'source_id' => $this->source->id,
            'parsed_company_name' => 'Acme Corp',
            'parsed_role_title' => 'Staff Engineer',
            ...$attributes,
        ]);
    }

    private function add(AggregatorListing $listing): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user)->post("/discover/{$listing->id}/pipeline");
    }

    #[Test]
    public function it_adds_the_existing_role_created_from_the_listing(): void
    {
        $company = Company::factory()->create(['name' => 'Acme Corp']);
        $listing = $this->listing(['matched_company_id' => $company->id, 'status' => AggregatorListingStatus::LINKED]);
        $role = Role::factory()->aggregator()->create([
            'company_id' => $company->id,
            'aggregator_listing_id' => $listing->id,
            'title' => 'Staff Engineer',
        ]);

        $this->add($listing)
            ->assertRedirect()
            ->assertSessionHas('success', 'Added Staff Engineer at Acme Corp to your applications.');

        $this->assertSame(PipelineStatus::RESEARCHING, $role->fresh()->pipeline_status);
        $this->assertSame(1, Role::query()->count());
    }

    #[Test]
    public function it_creates_a_role_for_a_matched_company(): void
    {
        $company = Company::factory()->create(['name' => 'Whatever']);
        $listing = $this->listing(['matched_company_id' => $company->id]);

        $this->add($listing)->assertSessionHas('success');

        $role = Role::query()->where('aggregator_listing_id', $listing->id)->firstOrFail();
        $this->assertSame($company->id, $role->company_id);
        $this->assertSame(PipelineStatus::RESEARCHING, $role->pipeline_status);
        $this->assertSame(1, Event::query()
            ->where('role_id', $role->id)
            ->where('type', EventType::NEW_ROLE->value)
            ->count());
    }

    #[Test]
    public function it_matches_an_unlinked_listing_to_an_existing_company_by_name(): void
    {
        $company = Company::factory()->create(['name' => 'Acme, Inc.']);
        $listing = $this->listing(['parsed_company_name' => 'Acme Corp']);

        $this->add($listing)->assertSessionHas('success');

        $this->assertSame(1, Company::query()->count());
        $this->assertSame($company->id, Role::query()->firstOrFail()->company_id);
    }

    #[Test]
    public function it_creates_a_tier_c_company_when_none_matches(): void
    {
        Company::factory()->create(['name' => 'Totally Different Co']);
        $listing = $this->listing(['parsed_company_name' => 'Brand New Labs']);

        $this->add($listing)->assertSessionHas('success');

        $company = Company::query()->where('name', 'Brand New Labs')->firstOrFail();
        $this->assertSame('C', $company->tier);
        $this->assertNull($company->ats_type);
        $this->assertSame(1, Event::query()
            ->where('company_id', $company->id)
            ->where('type', EventType::COMPANY_ADDED->value)
            ->count());
        $this->assertSame($company->id, Role::query()->firstOrFail()->company_id);
    }

    #[Test]
    public function it_is_idempotent_when_already_in_the_pipeline(): void
    {
        $listing = $this->listing();

        $this->add($listing)->assertSessionHas('success');
        $this->add($listing)
            ->assertSessionHas('success', 'Staff Engineer at Acme Corp is already in your applications.');

        $this->assertSame(1, Role::query()->count());
        $this->assertSame(1, Company::query()->count());
    }

    #[Test]
    public function it_changes_nothing_when_the_title_is_missing(): void
    {
        $listing = $this->listing(['parsed_role_title' => null]);

        $this->add($listing)->assertSessionHas('error');

        $this->assertSame(0, Role::query()->count());
        $this->assertSame(0, Company::query()->count());
        $this->assertSame(AggregatorListingStatus::NEW, $listing->fresh()->status);
    }

    #[Test]
    public function it_changes_nothing_when_the_company_name_is_empty(): void
    {
        $listing = $this->listing(['parsed_company_name' => null]);

        $this->add($listing)->assertSessionHas('error');

        $this->assertSame(0, Role::query()->count());
        $this->assertSame(0, Company::query()->count());
        $this->assertSame(AggregatorListingStatus::NEW, $listing->fresh()->status);
    }

    #[Test]
    public function it_marks_the_listing_linked_with_its_company(): void
    {
        $listing = $this->listing();

        $this->add($listing);

        $fresh = $listing->fresh();
        $this->assertSame(AggregatorListingStatus::LINKED, $fresh->status);
        $this->assertSame(Company::query()->firstOrFail()->id, $fresh->matched_company_id);
    }

    #[Test]
    public function another_tenants_listing_returns_404(): void
    {
        $other = Tenant::factory()->create();
        app(TenantContext::class)->setCurrent($other);
        $otherSource = AggregatorSource::factory()->ofType(AggregatorSourceType::HACKERNEWS)->create();
        $foreign = AggregatorListing::factory()->create(['source_id' => $otherSource->id]);
        app(TenantContext::class)->setCurrent($this->tenant);

        $this->add($foreign)->assertNotFound();
    }

    #[Test]
    public function discover_payload_exposes_pipeline_role_id(): void
    {
        $inPipeline = $this->listing();
        $notAdded = $this->listing(['parsed_company_name' => 'Other Co']);
        $this->add($inPipeline);
        $role = Role::query()->where('aggregator_listing_id', $inPipeline->id)->firstOrFail();

        $response = $this->actingAs($this->user)->get('/discover?status=linked');
        $this->assertSame(
            $role->id,
            collect($response->viewData('page')['props']['listings']['data'])->firstWhere('id', $inPipeline->id)['pipeline_role_id'],
        );

        $response = $this->actingAs($this->user)->get('/discover');
        $this->assertNull(
            collect($response->viewData('page')['props']['listings']['data'])->firstWhere('id', $notAdded->id)['pipeline_role_id'],
        );
    }
}
