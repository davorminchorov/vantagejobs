<?php

declare(strict_types=1);

namespace Tests\Feature\Scrapers\Aggregator;

use App\Enums\AggregatorListingStatus;
use App\Enums\AggregatorSourceType;
use App\Enums\EventType;
use App\Models\AggregatorListing;
use App\Models\AggregatorSource;
use App\Models\Company;
use App\Models\Event;
use App\Models\Role;
use App\Scrapers\Aggregator\AggregatorListingPersister;
use App\Scrapers\Aggregator\Parser\SourceContext;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * End-to-end coverage of PRD §6.4.6 auto-link: when a listing's parsed
 * company name matches an existing tracked Company (Jaro-Winkler ≥ 0.85),
 * the persister sets matched_company_id, flips status to `linked`, creates
 * a real Role from the listing (source 'aggregator:{type}'), and emits a
 * NEW_ROLE Event on the matched Company carrying that Role's id.
 *
 * Drives AggregatorListingPersister directly with synthesized SourceContexts
 * so the test doesn't depend on any concrete scraper.
 */
final class AggregatorAutoLinkTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private AggregatorSource $source;

    private AggregatorListingPersister $persister;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->setCurrent($this->tenant);

        $this->source = AggregatorSource::factory()->ofType(AggregatorSourceType::HACKERNEWS)->create();
        $this->persister = $this->app->make(AggregatorListingPersister::class);
    }

    #[Test]
    public function exact_normalized_match_links_listing_creates_role_and_emits_event(): void
    {
        $acme = Company::factory()->create(['name' => 'Acme Corp']);

        $this->upsertListing('vercel-edge-1', companyName: 'Acme, Inc.', roleTitle: 'Staff Engineer');

        $listing = AggregatorListing::query()->where('external_id', 'vercel-edge-1')->firstOrFail();
        $this->assertSame($acme->id, $listing->matched_company_id);
        $this->assertSame(AggregatorListingStatus::LINKED, $listing->status);

        $role = Role::query()->where('company_id', $acme->id)->sole();
        $this->assertSame('aggregator:hackernews', $role->source);
        $this->assertSame('vercel-edge-1', $role->external_id);
        $this->assertSame($listing->id, $role->aggregator_listing_id);
        $this->assertSame('Staff Engineer', $role->title);
        $this->assertTrue($role->is_open);

        $event = Event::query()->where('company_id', $acme->id)->sole();
        $this->assertSame(EventType::NEW_ROLE->value, $event->type);
        $this->assertSame('Seen on aggregator: Staff Engineer', $event->title);
        $this->assertSame($role->id, $event->role_id);

        $body = json_decode((string) $event->body, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($listing->id, $body['aggregator_listing_id']);
    }

    #[Test]
    public function missing_parsed_title_links_without_creating_a_role(): void
    {
        $acme = Company::factory()->create(['name' => 'Acme Corp']);

        // upsertListing() always supplies a title in `structured`, so drive
        // the persister directly with a blank one to exercise the parser
        // producing a null parsed_role_title.
        $context = new SourceContext(
            sourceType: AggregatorSourceType::HACKERNEWS,
            structured: ['company_name' => 'Acme Corp', 'title' => ''],
            bodyText: null,
            postedAt: null,
            externalId: 'no-title-1',
        );
        $this->persister->upsert($this->source, $context, ['title' => ''], 'no-title-1');

        $listing = AggregatorListing::query()->where('external_id', 'no-title-1')->firstOrFail();
        $this->assertSame($acme->id, $listing->matched_company_id);
        $this->assertSame(AggregatorListingStatus::LINKED, $listing->status);

        $this->assertSame(0, Role::query()->count());

        $event = Event::query()->where('company_id', $acme->id)->sole();
        $this->assertNull($event->role_id);
    }

    #[Test]
    public function fuzzy_match_above_threshold_links(): void
    {
        $stripe = Company::factory()->create(['name' => 'Stripe.com']);

        // 'Stripe' normalizes to 'stripe'; 'Stripe.com' normalizes to 'stripecom'.
        // Jaro-Winkler ≈ 0.93 (verified in MatchCompanyActionTest).
        $this->upsertListing('stripe-be-1', companyName: 'Stripe', roleTitle: 'Senior Backend');

        $listing = AggregatorListing::query()->where('external_id', 'stripe-be-1')->firstOrFail();
        $this->assertSame($stripe->id, $listing->matched_company_id);
        $this->assertSame(AggregatorListingStatus::LINKED, $listing->status);
    }

    #[Test]
    public function below_threshold_match_leaves_listing_new(): void
    {
        Company::factory()->create(['name' => 'Acme Corp']);

        $this->upsertListing('newco-1', companyName: 'Globex', roleTitle: 'Senior Engineer');

        $listing = AggregatorListing::query()->where('external_id', 'newco-1')->firstOrFail();
        $this->assertNull($listing->matched_company_id);
        $this->assertSame(AggregatorListingStatus::NEW, $listing->status);
        $this->assertSame(0, Event::query()->count());
    }

    #[Test]
    public function no_companies_means_no_match(): void
    {
        $this->upsertListing('only-listing', companyName: 'Acme', roleTitle: 'Engineer');

        $listing = AggregatorListing::query()->where('external_id', 'only-listing')->firstOrFail();
        $this->assertNull($listing->matched_company_id);
        $this->assertSame(AggregatorListingStatus::NEW, $listing->status);
    }

    #[Test]
    public function graveyarded_company_is_not_a_match_candidate(): void
    {
        Company::factory()->create(['name' => 'Acme Corp', 'is_active' => false]);

        $this->upsertListing('graveyard-1', companyName: 'Acme Corp', roleTitle: 'Engineer');

        $listing = AggregatorListing::query()->where('external_id', 'graveyard-1')->firstOrFail();
        $this->assertNull($listing->matched_company_id);
        $this->assertSame(AggregatorListingStatus::NEW, $listing->status);
        $this->assertSame(0, Event::query()->count());
    }

    #[Test]
    public function short_normalized_key_skips_fuzzy_match_even_above_threshold(): void
    {
        Company::factory()->create(['name' => 'Uber']);

        // 'Ubar' normalizes to 'ubar' (4 chars, below the 5-char fuzzy
        // floor). Jaro-Winkler('ubar', 'uber') ~= 0.867, comfortably above
        // the 0.85 threshold -- without the length floor this would
        // auto-link two distinct companies on a short-name near-miss.
        $this->upsertListing('short-name-1', companyName: 'Ubar', roleTitle: 'Engineer');

        $listing = AggregatorListing::query()->where('external_id', 'short-name-1')->firstOrFail();
        $this->assertNull($listing->matched_company_id);
        $this->assertSame(AggregatorListingStatus::NEW, $listing->status);
    }

    #[Test]
    public function missing_company_name_skips_auto_link_silently(): void
    {
        Company::factory()->create(['name' => 'Acme Corp']);

        $this->upsertListing('no-company', companyName: null, roleTitle: 'Generic Engineer');

        $listing = AggregatorListing::query()->where('external_id', 'no-company')->firstOrFail();
        $this->assertNull($listing->matched_company_id);
        $this->assertSame(0, Event::query()->count());
    }

    #[Test]
    public function rerunning_does_not_duplicate_event_or_role(): void
    {
        $acme = Company::factory()->create(['name' => 'Acme Corp']);

        $this->upsertListing('acme-staff-1', companyName: 'Acme Corp', roleTitle: 'Staff Engineer');
        $this->upsertListing('acme-staff-1', companyName: 'Acme Corp', roleTitle: 'Staff Engineer');

        $this->assertSame(1, AggregatorListing::query()->count());
        $this->assertSame(1, Event::query()->where('company_id', $acme->id)->count());
        $this->assertSame(1, Role::query()->where('company_id', $acme->id)->count());
    }

    #[Test]
    public function refetching_a_linked_listing_touches_its_roles_last_seen_at(): void
    {
        Company::factory()->create(['name' => 'Acme Corp']);

        $this->upsertListing('acme-refetch-1', companyName: 'Acme Corp', roleTitle: 'Staff Engineer');

        $role = Role::query()->where('external_id', 'acme-refetch-1')->sole();
        $role->forceFill(['last_seen_at' => CarbonImmutable::now()->subDays(10)])->save();

        $this->upsertListing('acme-refetch-1', companyName: 'Acme Corp', roleTitle: 'Staff Engineer');

        $role->refresh();
        $this->assertTrue($role->last_seen_at->greaterThan(CarbonImmutable::now()->subMinute()));
    }

    #[Test]
    public function event_occurred_at_uses_posted_at_when_present(): void
    {
        Company::factory()->create(['name' => 'Acme Corp']);
        $postedAt = CarbonImmutable::parse('2026-04-15T10:00:00Z');

        $this->upsertListing('acme-1', companyName: 'Acme Corp', roleTitle: 'Engineer', postedAt: $postedAt);

        $event = Event::query()->sole();
        $this->assertTrue($event->occurred_at->equalTo($postedAt));
    }

    private function upsertListing(
        string $externalId,
        ?string $companyName,
        string $roleTitle,
        ?CarbonImmutable $postedAt = null,
    ): ?int {
        $structured = [
            'title' => $roleTitle,
        ];
        if ($companyName !== null) {
            $structured['company_name'] = $companyName;
        }

        $context = new SourceContext(
            sourceType: AggregatorSourceType::HACKERNEWS,
            structured: $structured,
            bodyText: null,
            postedAt: $postedAt,
            externalId: $externalId,
        );

        return $this->persister->upsert($this->source, $context, $structured, $externalId);
    }
}
