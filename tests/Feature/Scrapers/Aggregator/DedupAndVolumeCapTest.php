<?php

declare(strict_types=1);

namespace Tests\Feature\Scrapers\Aggregator;

use App\Enums\AggregatorListingStatus;
use App\Enums\AggregatorSourceType;
use App\Models\AggregatorListing;
use App\Models\AggregatorSource;
use App\Scrapers\Aggregator\AggregatorListingPersister;
use App\Scrapers\Aggregator\CompanyKeyNormalizer;
use App\Scrapers\Aggregator\MatchCompanyAction;
use App\Scrapers\Aggregator\Parser\ParserPipeline;
use App\Scrapers\Aggregator\Parser\SourceContext;
use App\Scrapers\Aggregator\RoleHasher;
use App\Scrapers\Aggregator\VolumeCapAction;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Step 14 ingestion gates:
 *   - Cross-source dedup within 14 days on (company_key, role_key, location_bucket)
 *   - Volume cap: > N NEW per run -> demote sub-K to OVERFLOW by signal
 */
final class DedupAndVolumeCapTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private AggregatorSource $sourceA;

    private AggregatorSource $sourceB;

    private AggregatorListingPersister $persister;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->setCurrent($this->tenant);

        $this->sourceA = AggregatorSource::factory()->ofType(AggregatorSourceType::HACKERNEWS)->create();
        $this->sourceB = AggregatorSource::factory()->ofType(AggregatorSourceType::REMOTIVE)->create();

        $this->persister = $this->app->make(AggregatorListingPersister::class);
    }

    /* --------------------------------------------------------------
     | Cross-source dedup
     * -------------------------------------------------------------- */

    #[Test]
    public function second_listing_same_company_role_region_within_window_is_marked_duplicate(): void
    {
        $firstId = $this->upsert($this->sourceA, 'hn-1', 'Acme Corp', 'Senior Backend Engineer');
        $secondId = $this->upsert($this->sourceB, 'rem-1', 'Acme, Inc.', 'Senior Backend Engineer');

        $first = AggregatorListing::query()->findOrFail($firstId);
        $second = AggregatorListing::query()->findOrFail($secondId);

        $this->assertSame(AggregatorListingStatus::NEW, $first->status);
        $this->assertSame(AggregatorListingStatus::DUPLICATE, $second->status);
        $this->assertSame($first->id, $second->duplicate_of_id);
    }

    #[Test]
    public function third_match_points_at_the_earliest_visible_canonical(): void
    {
        $firstId = $this->upsert($this->sourceA, 'hn-1', 'Acme Corp', 'Senior Engineer');
        $this->upsert($this->sourceB, 'rem-1', 'Acme', 'Senior Engineer');
        $thirdId = $this->upsert($this->sourceA, 'hn-2', 'Acme', 'Senior Engineer');

        $third = AggregatorListing::query()->findOrFail($thirdId);
        $this->assertSame(AggregatorListingStatus::DUPLICATE, $third->status);
        $this->assertSame($firstId, $third->duplicate_of_id);
    }

    #[Test]
    public function different_role_does_not_dedup(): void
    {
        $this->upsert($this->sourceA, 'hn-1', 'Acme', 'Backend Engineer');
        $secondId = $this->upsert($this->sourceB, 'rem-1', 'Acme', 'Frontend Engineer');

        $second = AggregatorListing::query()->findOrFail($secondId);
        $this->assertSame(AggregatorListingStatus::NEW, $second->status);
    }

    #[Test]
    public function different_region_does_not_dedup(): void
    {
        $this->upsert($this->sourceA, 'hn-1', 'Acme', 'Backend Engineer', region: 'emea');
        $secondId = $this->upsert($this->sourceB, 'rem-1', 'Acme', 'Backend Engineer', region: 'us');

        $this->assertSame(AggregatorListingStatus::NEW, AggregatorListing::query()->find($secondId)->status);
    }

    #[Test]
    public function match_outside_14_day_window_does_not_dedup(): void
    {
        $firstId = $this->upsert($this->sourceA, 'hn-1', 'Acme', 'Backend Engineer');
        AggregatorListing::query()->whereKey($firstId)->update([
            'fetched_at' => CarbonImmutable::now()->subDays(15),
        ]);

        $secondId = $this->upsert($this->sourceB, 'rem-1', 'Acme', 'Backend Engineer');

        $second = AggregatorListing::query()->findOrFail($secondId);
        $this->assertSame(AggregatorListingStatus::NEW, $second->status);
        $this->assertNull($second->duplicate_of_id);
    }

    #[Test]
    public function dismissed_canonical_does_not_block_fresh_new(): void
    {
        $firstId = $this->upsert($this->sourceA, 'hn-1', 'Acme', 'Backend Engineer');
        AggregatorListing::query()->whereKey($firstId)->update([
            'status' => AggregatorListingStatus::DISMISSED->value,
        ]);

        $secondId = $this->upsert($this->sourceB, 'rem-1', 'Acme', 'Backend Engineer');

        $this->assertSame(AggregatorListingStatus::NEW, AggregatorListing::query()->find($secondId)->status);
    }

    #[Test]
    public function listings_with_missing_keys_skip_dedup(): void
    {
        // No company name in second listing — no key to dedup against.
        $this->upsert($this->sourceA, 'hn-1', 'Acme', 'Backend Engineer');
        $secondId = $this->upsert($this->sourceB, 'rem-1', null, 'Backend Engineer');

        $this->assertSame(AggregatorListingStatus::NEW, AggregatorListing::query()->find($secondId)->status);
    }

    #[Test]
    public function duplicate_listing_does_not_emit_new_role_event(): void
    {
        // Create a tracked Company so auto-link WOULD fire on second listing if
        // dedup didn't pre-empt it.
        \App\Models\Company::factory()->create(['name' => 'Acme Corp']);

        $this->upsert($this->sourceA, 'hn-1', 'Acme Corp', 'Backend Engineer');
        \App\Models\Event::query()->delete(); // reset the event from first listing

        $this->upsert($this->sourceB, 'rem-1', 'Acme Corp', 'Backend Engineer');

        $this->assertSame(0, \App\Models\Event::query()->count());
    }

    /* --------------------------------------------------------------
     | Volume cap
     * -------------------------------------------------------------- */

    #[Test]
    public function under_threshold_does_not_demote(): void
    {
        $persister = $this->capPersister(trigger: 5, keep: 2);

        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = $this->upsertVia($persister, $this->sourceA, "hn-{$i}", "Co {$i}", "Role {$i}");
        }
        $persister->capRun($ids);

        $this->assertSame(
            5,
            AggregatorListing::query()->where('status', AggregatorListingStatus::NEW->value)->count(),
        );
        $this->assertSame(0, AggregatorListing::query()->where('status', AggregatorListingStatus::OVERFLOW->value)->count());
    }

    #[Test]
    public function over_threshold_demotes_to_top_keep_by_signal(): void
    {
        $persister = $this->capPersister(trigger: 5, keep: 2);

        // 6 listings, varied signal:
        //   0: has_comp + remote -> score 2
        //   1: has_comp           -> score 1
        //   2: remote             -> score 1
        //   3: neither            -> score 0
        //   4: has_comp + remote -> score 2
        //   5: neither            -> score 0
        $specs = [
            ['has_comp' => true, 'remote' => 'remote'],
            ['has_comp' => true, 'remote' => null],
            ['has_comp' => false, 'remote' => 'remote'],
            ['has_comp' => false, 'remote' => null],
            ['has_comp' => true, 'remote' => 'remote'],
            ['has_comp' => false, 'remote' => null],
        ];

        $ids = [];
        foreach ($specs as $i => $spec) {
            $ids[] = $this->upsertVia($persister, $this->sourceA, "hn-{$i}", "Co {$i}", "Role {$i}");
            AggregatorListing::query()->whereKey($ids[$i])->update([
                'has_comp' => $spec['has_comp'],
                'parsed_remote_type' => $spec['remote'],
                'posted_at' => CarbonImmutable::now()->subMinutes(10 - $i),
            ]);
        }

        $demoted = $persister->capRun($ids);

        // 6 NEW > 5 trigger → cap. Keep top 2 by score (0 and 4, both score 2),
        // tie broken by posted_at desc — index 4 has the newer posted_at. After
        // them, listings 1, 2, 3, 5 are demoted.
        $this->assertSame(4, $demoted);
        $this->assertSame(2, AggregatorListing::query()->where('status', AggregatorListingStatus::NEW->value)->count());
        $this->assertSame(4, AggregatorListing::query()->where('status', AggregatorListingStatus::OVERFLOW->value)->count());

        $kept = AggregatorListing::query()
            ->where('status', AggregatorListingStatus::NEW->value)
            ->pluck('id')
            ->all();
        sort($kept);
        $this->assertSame([$ids[0], $ids[4]], $kept);
    }

    #[Test]
    public function null_posted_at_falls_back_to_fetched_at_for_tiebreak(): void
    {
        $persister = $this->capPersister(trigger: 1, keep: 1);

        // Same score (0) for both, so the tiebreak decides. X has no
        // posted_at (a source that never populates it, e.g. python_org)
        // but was fetched moments ago; Y has a real posted_at, but it's
        // older than X's fetched_at. Without the fetched_at fallback, X's
        // null posted_at ranks as the oldest possible timestamp regardless
        // of freshness, so it would lose the tiebreak every time.
        $x = $this->upsertVia($persister, $this->sourceA, 'null-posted', 'Co X', 'Role X');
        AggregatorListing::query()->whereKey($x)->update([
            'posted_at' => null,
            'fetched_at' => CarbonImmutable::now(),
        ]);

        $y = $this->upsertVia($persister, $this->sourceA, 'dated-posted', 'Co Y', 'Role Y');
        AggregatorListing::query()->whereKey($y)->update([
            'posted_at' => CarbonImmutable::now()->subMinutes(5),
            'fetched_at' => CarbonImmutable::now()->subMinutes(10),
        ]);

        $demoted = $persister->capRun([$x, $y]);

        $this->assertSame(1, $demoted);
        $this->assertSame(AggregatorListingStatus::NEW, AggregatorListing::query()->find($x)->status);
        $this->assertSame(AggregatorListingStatus::OVERFLOW, AggregatorListing::query()->find($y)->status);
    }

    #[Test]
    public function cap_ignores_listings_not_in_new_status(): void
    {
        $persister = $this->capPersister(trigger: 2, keep: 1);

        // 3 listings: 2 NEW + 1 DUPLICATE (dedup gated it). Only 2 NEW counted,
        // 2 is not > 2 trigger → no demotion.
        $a = $this->upsertVia($persister, $this->sourceA, 'a', 'Acme', 'Senior Backend');
        $b = $this->upsertVia($persister, $this->sourceB, 'b', 'Acme', 'Senior Backend'); // dup of $a
        $c = $this->upsertVia($persister, $this->sourceA, 'c', 'Globex', 'Frontend');

        $this->assertSame(AggregatorListingStatus::DUPLICATE, AggregatorListing::query()->find($b)->status);

        $demoted = $persister->capRun([$a, $b, $c]);

        $this->assertSame(0, $demoted);
        $this->assertSame(2, AggregatorListing::query()->where('status', AggregatorListingStatus::NEW->value)->count());
    }

    #[Test]
    public function empty_id_list_is_a_noop(): void
    {
        $this->assertSame(0, $this->persister->capRun([]));
    }

    /* --------------------------------------------------------------
     | Helpers
     * -------------------------------------------------------------- */

    private function upsert(
        AggregatorSource $source,
        string $externalId,
        ?string $companyName,
        string $roleTitle,
        string $region = 'worldwide',
    ): int {
        return $this->upsertVia($this->persister, $source, $externalId, $companyName, $roleTitle, $region);
    }

    private function upsertVia(
        AggregatorListingPersister $persister,
        AggregatorSource $source,
        string $externalId,
        ?string $companyName,
        string $roleTitle,
        string $region = 'worldwide',
    ): int {
        $structured = [
            'title' => $roleTitle,
            'location' => match ($region) {
                'us' => 'United States',
                'emea' => 'EMEA',
                'apac' => 'APAC',
                default => 'Worldwide',
            },
        ];
        if ($companyName !== null) {
            $structured['company_name'] = $companyName;
        }

        $context = new SourceContext(
            sourceType: $source->type,
            structured: $structured,
            externalId: $externalId,
        );

        $id = $persister->upsert($source, $context, $structured, $externalId);
        $this->assertNotNull($id, "upsert should have created a row for {$externalId}");

        return $id;
    }

    private function capPersister(int $trigger, int $keep): AggregatorListingPersister
    {
        return new AggregatorListingPersister(
            pipeline: $this->app->make(ParserPipeline::class),
            normalizer: new CompanyKeyNormalizer(),
            matcher: new MatchCompanyAction(new CompanyKeyNormalizer()),
            volumeCap: new VolumeCapAction(triggerThreshold: $trigger, keepTop: $keep),
            hasher: new RoleHasher(),
        );
    }
}
