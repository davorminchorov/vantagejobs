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
 * the persister sets matched_company_id, flips status to `linked`, and
 * emits a NEW_ROLE Event on the matched Company.
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
    public function exact_normalized_match_links_listing_and_emits_event(): void
    {
        $acme = Company::factory()->create(['name' => 'Acme Corp']);

        $this->upsertListing('vercel-edge-1', companyName: 'Acme, Inc.', roleTitle: 'Staff Engineer');

        $listing = AggregatorListing::query()->where('external_id', 'vercel-edge-1')->firstOrFail();
        $this->assertSame($acme->id, $listing->matched_company_id);
        $this->assertSame(AggregatorListingStatus::LINKED, $listing->status);

        $event = Event::query()->where('company_id', $acme->id)->sole();
        $this->assertSame(EventType::NEW_ROLE->value, $event->type);
        $this->assertSame('Staff Engineer', $event->title);
        $this->assertNull($event->role_id);

        $body = json_decode((string) $event->body, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($listing->id, $body['aggregator_listing_id']);
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
    public function missing_company_name_skips_auto_link_silently(): void
    {
        Company::factory()->create(['name' => 'Acme Corp']);

        $this->upsertListing('no-company', companyName: null, roleTitle: 'Generic Engineer');

        $listing = AggregatorListing::query()->where('external_id', 'no-company')->firstOrFail();
        $this->assertNull($listing->matched_company_id);
        $this->assertSame(0, Event::query()->count());
    }

    #[Test]
    public function rerunning_does_not_duplicate_event(): void
    {
        $acme = Company::factory()->create(['name' => 'Acme Corp']);

        $this->upsertListing('acme-staff-1', companyName: 'Acme Corp', roleTitle: 'Staff Engineer');
        $this->upsertListing('acme-staff-1', companyName: 'Acme Corp', roleTitle: 'Staff Engineer');

        $this->assertSame(1, AggregatorListing::query()->count());
        $this->assertSame(1, Event::query()->where('company_id', $acme->id)->count());
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
    ): bool {
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
