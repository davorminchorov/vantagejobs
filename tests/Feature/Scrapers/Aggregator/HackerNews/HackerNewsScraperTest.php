<?php

declare(strict_types=1);

namespace Tests\Feature\Scrapers\Aggregator\HackerNews;

use App\Enums\AggregatorListingStatus;
use App\Enums\AggregatorSourceType;
use App\Models\AggregatorListing;
use App\Models\AggregatorSource;
use App\Scrapers\Aggregator\HackerNews\HackerNewsScraper;
use App\Scrapers\Aggregator\HackerNews\Requests\FetchThreadRequest;
use App\Scrapers\Aggregator\HackerNews\Requests\FindLatestThreadRequest;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Laravel\Facades\Saloon;
use Tests\TestCase;

final class HackerNewsScraperTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private AggregatorSource $source;

    private HackerNewsScraper $scraper;

    private const STORY_ID = '40000000';

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->setCurrent($this->tenant);

        $this->source = AggregatorSource::factory()->ofType(AggregatorSourceType::HACKERNEWS)->create();
        $this->scraper = $this->app->make(HackerNewsScraper::class);
    }

    #[Test]
    public function happy_path_fetches_thread_and_persists_listings(): void
    {
        $this->fakeAlgolia($this->buildThread($this->loadFixtureChildren()));

        $result = $this->scraper->run($this->source);

        $this->assertFalse($result->hasErrors());
        $this->assertSame(15, $result->fetched);
        $this->assertSame(15, $result->newCount);
        $this->assertSame(15, AggregatorListing::query()->count());
    }

    #[Test]
    public function persists_canonical_listing_with_denormalized_fields(): void
    {
        $this->fakeAlgolia($this->buildThread($this->loadFixtureChildren()));

        $this->scraper->run($this->source);

        $vercel = AggregatorListing::query()->where('external_id', '40000001')->firstOrFail();
        $this->assertSame('Vercel', $vercel->parsed_company_name);
        $this->assertSame('Staff Engineer, Edge', $vercel->parsed_role_title);
        $this->assertSame('staff', $vercel->parsed_seniority);
        $this->assertSame('remote', $vercel->parsed_remote_type);
        $this->assertSame(220000, $vercel->parsed_comp_min_usd);
        $this->assertSame(280000, $vercel->parsed_comp_max_usd);
        $this->assertTrue($vercel->has_comp);
        $this->assertContains('typescript', $vercel->stack_tags);
        $this->assertContains('react', $vercel->stack_tags);
        $this->assertSame('vercel', $vercel->normalized_company_key);
        $this->assertSame(AggregatorListingStatus::NEW, $vercel->status);
        $this->assertNotNull($vercel->fetched_at);
        $this->assertSame((int) $this->source->id, (int) $vercel->source_id);
    }

    #[Test]
    public function rerunning_against_identical_thread_is_idempotent(): void
    {
        $thread = $this->buildThread($this->loadFixtureChildren());
        $this->fakeAlgolia($thread);

        $first = $this->scraper->run($this->source);
        $this->assertSame(15, $first->newCount);

        $this->fakeAlgolia($thread);
        $second = $this->scraper->run($this->source);

        $this->assertSame(15, $second->fetched);
        $this->assertSame(0, $second->newCount);
        $this->assertSame(15, AggregatorListing::query()->count());
    }

    #[Test]
    public function preserves_user_status_on_refetch(): void
    {
        $this->fakeAlgolia($this->buildThread($this->loadFixtureChildren()));
        $this->scraper->run($this->source);

        // Simulate the user dismissing one listing in the triage UI.
        $row = AggregatorListing::query()->where('external_id', '40000001')->firstOrFail();
        $row->status = AggregatorListingStatus::DISMISSED;
        $row->save();

        $this->fakeAlgolia($this->buildThread($this->loadFixtureChildren()));
        $this->scraper->run($this->source);

        $this->assertSame(
            AggregatorListingStatus::DISMISSED,
            AggregatorListing::query()->where('external_id', '40000001')->firstOrFail()->status,
        );
    }

    #[Test]
    public function skips_whoishiring_op_replies_and_short_or_deleted_comments(): void
    {
        $this->fakeAlgolia($this->buildThread([
            // Should be skipped: the OP / sub-threads sometimes show up as children.
            ['id' => 1001, 'author' => 'whoishiring', 'text' => 'See the rules pinned in the description for posters.', 'created_at' => '2026-05-01T00:00:00Z'],
            // Should be skipped: empty text (deleted).
            ['id' => 1002, 'author' => 'someone', 'text' => null, 'created_at' => '2026-05-01T00:00:00Z'],
            // Should be skipped: under 100 chars stripped.
            ['id' => 1003, 'author' => 'someone', 'text' => '<p>too short</p>', 'created_at' => '2026-05-01T00:00:00Z'],
            // Should be kept: a real posting.
            ['id' => 1004, 'author' => 'real_recruiter', 'text' => '<p>Acme | Senior Backend Engineer | Remote (EMEA) | $150k-$200k | Python, Postgres, AWS | https://acme.com/jobs - lots of body content to clear the 100 char floor easily here.</p>', 'created_at' => '2026-05-01T00:00:00Z'],
        ]));

        $result = $this->scraper->run($this->source);

        $this->assertSame(1, $result->fetched);
        $this->assertSame(1, $result->newCount);
        $this->assertSame(1, AggregatorListing::query()->count());
        $this->assertSame('1004', AggregatorListing::query()->first()->external_id);
    }

    #[Test]
    public function records_no_thread_error_when_search_is_empty(): void
    {
        Saloon::fake([
            FindLatestThreadRequest::class => MockResponse::make(['hits' => []], 200),
        ]);

        $result = $this->scraper->run($this->source);

        $this->assertTrue($result->hasErrors());
        $this->assertSame(0, $result->fetched);
        $this->assertSame(0, $result->newCount);
        $this->assertStringContainsString('No HackerNews "Who is Hiring" thread', $result->errors[0]['message']);
    }

    #[Test]
    public function thread_fetch_exception_returns_errors_without_persisting(): void
    {
        Saloon::fake([
            FindLatestThreadRequest::class => MockResponse::make([
                'hits' => [['objectID' => self::STORY_ID]],
            ], 200),
            FetchThreadRequest::class => function (PendingRequest $request): MockResponse {
                throw new RuntimeException('algolia 503');
            },
        ]);

        $result = $this->scraper->run($this->source);

        $this->assertTrue($result->hasErrors());
        $this->assertSame('algolia 503', $result->errors[0]['message']);
        $this->assertSame(0, AggregatorListing::query()->count());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadFixtureChildren(): array
    {
        $dir = __DIR__.'/../../../../Fixtures/parsers/hackernews';
        $files = glob($dir.'/*.json');
        $this->assertNotFalse($files);
        sort($files);

        $children = [];
        foreach ($files as $path) {
            $raw = file_get_contents($path);
            $this->assertNotFalse($raw);
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

            $children[] = [
                'id' => (int) $decoded['comment_id'],
                'author' => $decoded['author'] ?? 'unknown',
                'text' => $decoded['text'],
                'created_at' => $decoded['created_at'] ?? null,
                'children' => [],
            ];
        }

        return $children;
    }

    /**
     * @param  list<array<string, mixed>>  $children
     * @return array<string, mixed>
     */
    private function buildThread(array $children): array
    {
        return [
            'id' => (int) self::STORY_ID,
            'title' => 'Ask HN: Who is Hiring? (May 2026)',
            'author' => 'whoishiring',
            'children' => $children,
        ];
    }

    /**
     * @param  array<string, mixed>  $thread
     */
    private function fakeAlgolia(array $thread): void
    {
        Saloon::fake([
            FindLatestThreadRequest::class => MockResponse::make([
                'hits' => [['objectID' => self::STORY_ID, 'title' => 'Ask HN: Who is Hiring?']],
            ], 200),
            FetchThreadRequest::class => MockResponse::make($thread, 200),
        ]);
    }
}
