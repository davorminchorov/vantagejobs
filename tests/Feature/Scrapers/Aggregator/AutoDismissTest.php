<?php

declare(strict_types=1);

namespace Tests\Feature\Scrapers\Aggregator;

use App\Enums\AggregatorListingStatus;
use App\Enums\AggregatorSourceType;
use App\Models\AggregatorListing;
use App\Models\AggregatorSource;
use App\Models\Company;
use App\Models\DismissedCompany;
use App\Models\Event;
use App\Models\User;
use App\Scrapers\Aggregator\AggregatorListingPersister;
use App\Scrapers\Aggregator\DismissListingAction;
use App\Scrapers\Aggregator\Parser\SourceContext;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Auto-dismiss memory per PRD §6.4.4: manual dismissals accumulate per
 * normalized company key; on the third, the rule auto-flips so future
 * listings from that employer arrive AUTO_DISMISSED and bypass triage.
 */
final class AutoDismissTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private AggregatorSource $source;

    private AggregatorListingPersister $persister;

    private DismissListingAction $dismisser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->setCurrent($this->tenant);

        $this->source = AggregatorSource::factory()->ofType(AggregatorSourceType::HACKERNEWS)->create();
        $this->persister = $this->app->make(AggregatorListingPersister::class);
        $this->dismisser = $this->app->make(DismissListingAction::class);
    }

    /* --------------------------------------------------------------
     | DismissListingAction
     * -------------------------------------------------------------- */

    #[Test]
    public function first_dismissal_creates_rule_at_count_one(): void
    {
        $listing = $this->seedListing('hn-1', 'Acme Corp');

        $result = $this->dismisser->dismiss($listing);

        $this->assertSame(AggregatorListingStatus::DISMISSED, $listing->fresh()->status);
        $this->assertNotNull($result->rule);
        $this->assertSame('acme', $result->rule->normalized_company_key);
        $this->assertSame(1, $result->rule->dismiss_count);
        $this->assertFalse($result->rule->auto_dismiss);
        $this->assertFalse($result->ruleFlippedToAutoDismiss);
    }

    #[Test]
    public function second_dismissal_increments_but_does_not_flip(): void
    {
        $this->dismisser->dismiss($this->seedListing('hn-1', 'Acme Corp'));
        $result = $this->dismisser->dismiss($this->seedListing('hn-2', 'Acme, Inc.'));

        $this->assertSame(2, $result->rule->dismiss_count);
        $this->assertFalse($result->rule->auto_dismiss);
        $this->assertFalse($result->ruleFlippedToAutoDismiss);
    }

    #[Test]
    public function third_dismissal_flips_auto_dismiss(): void
    {
        $this->dismisser->dismiss($this->seedListing('hn-1', 'Acme Corp'));
        $this->dismisser->dismiss($this->seedListing('hn-2', 'Acme, Inc.'));
        $result = $this->dismisser->dismiss($this->seedListing('hn-3', 'Acme Co'));

        $this->assertSame(3, $result->rule->dismiss_count);
        $this->assertTrue($result->rule->auto_dismiss);
        $this->assertTrue($result->ruleFlippedToAutoDismiss, 'should flag the threshold-cross');
    }

    #[Test]
    public function fourth_dismissal_does_not_set_ruleFlipped_again(): void
    {
        $this->dismisser->dismiss($this->seedListing('hn-1', 'Acme Corp'));
        $this->dismisser->dismiss($this->seedListing('hn-2', 'Acme, Inc.'));
        $this->dismisser->dismiss($this->seedListing('hn-3', 'Acme Co'));
        $result = $this->dismisser->dismiss($this->seedListing('hn-4', 'Acme'));

        $this->assertSame(4, $result->rule->dismiss_count);
        $this->assertTrue($result->rule->auto_dismiss);
        $this->assertFalse($result->ruleFlippedToAutoDismiss, 'flag is one-shot at threshold');
    }

    #[Test]
    public function dismissing_an_already_dismissed_listing_is_a_noop(): void
    {
        $listing = $this->seedListing('hn-1', 'Acme Corp');

        $first = $this->dismisser->dismiss($listing);
        $second = $this->dismisser->dismiss($listing->fresh());

        $this->assertFalse($first->alreadyDismissed);
        $this->assertTrue($second->alreadyDismissed);
        $this->assertSame(1, DismissedCompany::query()->sole()->dismiss_count);
    }

    #[Test]
    public function listing_without_parsed_company_name_dismisses_but_no_rule_row(): void
    {
        $listing = $this->seedListing('hn-1', null);

        $result = $this->dismisser->dismiss($listing);

        $this->assertSame(AggregatorListingStatus::DISMISSED, $listing->fresh()->status);
        $this->assertNull($result->rule);
        $this->assertSame(0, DismissedCompany::query()->count());
    }

    /* --------------------------------------------------------------
     | Persister gating against auto-dismiss
     * -------------------------------------------------------------- */

    #[Test]
    public function future_listings_from_auto_dismissed_employer_arrive_auto_dismissed(): void
    {
        // Cross the threshold manually.
        $this->dismisser->dismiss($this->seedListing('hn-1', 'Globex'));
        $this->dismisser->dismiss($this->seedListing('hn-2', 'Globex, Inc.'));
        $this->dismisser->dismiss($this->seedListing('hn-3', 'Globex Co'));

        // Ingest a new listing — should NOT land as NEW.
        $this->persister->upsert(
            $this->source,
            $this->context('hn-4', 'Globex, LLC', 'Senior Engineer'),
            ['title' => 'Senior Engineer', 'company_name' => 'Globex, LLC'],
            'hn-4',
        );

        $row = AggregatorListing::query()->where('external_id', 'hn-4')->firstOrFail();
        $this->assertSame(AggregatorListingStatus::AUTO_DISMISSED, $row->status);
    }

    #[Test]
    public function auto_dismissed_listing_skips_auto_link_event(): void
    {
        // Auto-dismiss rule + a tracked company with the same key.
        Company::factory()->create(['name' => 'Globex']);
        $this->dismisser->dismiss($this->seedListing('hn-1', 'Globex'));
        $this->dismisser->dismiss($this->seedListing('hn-2', 'Globex'));
        $this->dismisser->dismiss($this->seedListing('hn-3', 'Globex'));

        // Reset events: only future-ingest event should be checked.
        Event::query()->delete();

        $this->persister->upsert(
            $this->source,
            $this->context('hn-4', 'Globex', 'Engineer'),
            ['title' => 'Engineer', 'company_name' => 'Globex'],
            'hn-4',
        );

        $row = AggregatorListing::query()->where('external_id', 'hn-4')->firstOrFail();
        $this->assertSame(AggregatorListingStatus::AUTO_DISMISSED, $row->status);
        $this->assertNull($row->matched_company_id);
        $this->assertSame(0, Event::query()->count());
    }

    #[Test]
    public function listings_from_dismissed_but_not_auto_dismissed_employers_still_arrive_new(): void
    {
        // Two manual dismissals only — below threshold.
        $this->dismisser->dismiss($this->seedListing('hn-1', 'Wayne Enterprises'));
        $this->dismisser->dismiss($this->seedListing('hn-2', 'Wayne Enterprises'));

        $this->persister->upsert(
            $this->source,
            $this->context('hn-3', 'Wayne Enterprises', 'Engineer'),
            ['title' => 'Engineer', 'company_name' => 'Wayne Enterprises'],
            'hn-3',
        );

        $row = AggregatorListing::query()->where('external_id', 'hn-3')->firstOrFail();
        $this->assertSame(AggregatorListingStatus::NEW, $row->status);
    }

    /* --------------------------------------------------------------
     | Dashboard banner exposure
     * -------------------------------------------------------------- */

    #[Test]
    public function dashboard_shares_active_auto_dismiss_rules_to_inertia(): void
    {
        DismissedCompany::factory()->auto()->create([
            'normalized_company_key' => 'globex',
            'display_name' => 'Globex',
            'dismiss_count' => 5,
        ]);
        DismissedCompany::factory()->create([
            'normalized_company_key' => 'wayne',
            'display_name' => 'Wayne Enterprises',
            'dismiss_count' => 2,
            'auto_dismiss' => false,
        ]);

        $user = User::factory()->create();
        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $props = $response->viewData('page')['props'];
        $this->assertCount(1, $props['active_auto_dismiss_rules']);
        $this->assertSame('Globex', $props['active_auto_dismiss_rules'][0]['display_name']);
        $this->assertSame(5, $props['active_auto_dismiss_rules'][0]['dismiss_count']);
    }

    /* --------------------------------------------------------------
     | Helpers
     * -------------------------------------------------------------- */

    private function seedListing(string $externalId, ?string $companyName): AggregatorListing
    {
        $structured = ['title' => 'Engineer'];
        if ($companyName !== null) {
            $structured['company_name'] = $companyName;
        }

        $this->persister->upsert(
            $this->source,
            $this->context($externalId, $companyName, 'Engineer'),
            $structured,
            $externalId,
        );

        return AggregatorListing::query()->where('external_id', $externalId)->firstOrFail();
    }

    private function context(string $externalId, ?string $companyName, string $roleTitle): SourceContext
    {
        $structured = ['title' => $roleTitle];
        if ($companyName !== null) {
            $structured['company_name'] = $companyName;
        }

        return new SourceContext(
            sourceType: AggregatorSourceType::HACKERNEWS,
            structured: $structured,
            bodyText: null,
            externalId: $externalId,
        );
    }
}
