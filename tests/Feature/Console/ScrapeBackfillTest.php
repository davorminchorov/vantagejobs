<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\AggregatorSourceType;
use App\Models\AggregatorListing;
use App\Models\AggregatorSource;
use App\Models\ScrapeRunArtifact;
use App\Scrapers\Aggregator\HackerNews\Requests\FetchThreadRequest;
use App\Scrapers\Aggregator\HackerNews\Requests\FindHistoricalThreadsRequest;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;
use Tests\TestCase;

final class ScrapeBackfillTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private FilesystemFactory $filesystem;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create(['slug' => 'primary']);
        app(TenantContext::class)->setCurrent($this->tenant);
        $this->filesystem = $this->app->make(FilesystemFactory::class);
    }

    #[Test]
    public function requires_tenant_and_source(): void
    {
        $this->assertSame(1, Artisan::call('scrape:backfill'));
        $this->assertSame(1, Artisan::call('scrape:backfill', ['--tenant' => 'primary']));
        $this->assertSame(1, Artisan::call('scrape:backfill', ['--source' => 'hackernews']));
        $this->assertSame(1, Artisan::call('scrape:backfill', ['--tenant' => 'primary', '--source' => 'craigslist']));
    }

    /**
     * `--source` accepting a value that isn't a backing AggregatorSourceType
     * case at all (e.g. "craigslist" above) already failed cleanly. This
     * covers the other half: a value that IS a valid, seeded aggregator
     * source type but has no backfill replay strategy implemented
     * (ScrapeBackfillAction::backfill() only covers 5 of the 15 cases) —
     * the command must reject it before ever calling the action, instead
     * of letting an UnhandledMatchError escape.
     */
    #[Test]
    public function rejects_a_valid_but_unsupported_source_type_cleanly(): void
    {
        AggregatorSource::factory()->ofType(AggregatorSourceType::JOBICY)->create();

        $exit = Artisan::call('scrape:backfill', [
            '--tenant' => 'primary',
            '--source' => 'jobicy',
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Unknown or unsupported source', Artisan::output());
        $this->assertSame(0, AggregatorListing::query()->count());
    }

    #[Test]
    public function hackernews_pulls_historical_threads_via_algolia(): void
    {
        AggregatorSource::factory()->ofType(AggregatorSourceType::HACKERNEWS)->create();

        Saloon::fake([
            FindHistoricalThreadsRequest::class => MockResponse::make([
                'hits' => [
                    ['objectID' => '40000001'],
                    ['objectID' => '40000002'],
                    ['objectID' => '40000003'],
                ],
            ], 200),
            FetchThreadRequest::class => MockResponse::make([
                'id' => 40000001,
                'author' => 'whoishiring',
                'children' => [
                    [
                        'id' => 50000001,
                        'author' => 'someone',
                        'text' => 'Acme | Senior Backend Engineer | REMOTE | $150,000 - $200,000 | python, postgres '.str_repeat('We are hiring. ', 20),
                        'created_at' => '2026-05-01T12:00:00Z',
                    ],
                ],
            ], 200),
        ]);

        $exit = Artisan::call('scrape:backfill', [
            '--tenant' => 'primary',
            '--source' => 'hackernews',
            '--days' => '3',
        ]);

        $this->assertSame(0, $exit);

        $payload = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('hackernews', $payload['source']);
        $this->assertSame(3, $payload['days_requested']);
        $this->assertSame(3, $payload['threads_pulled']);
        // Same mock returns one listing for every thread; the persister is
        // idempotent on (source, external_id) so we see 1 new and 2 updates.
        $this->assertSame(1, $payload['new_listings']);
        $this->assertSame(2, $payload['updated_listings']);
        $this->assertSame(0, $payload['out_of_retention']);
    }

    #[Test]
    public function remotive_replays_artifacts_within_retention_window(): void
    {
        $source = AggregatorSource::factory()->ofType(AggregatorSourceType::REMOTIVE)->create();
        $disk = $this->filesystem->disk('local');
        $now = CarbonImmutable::now();

        $bodies = [
            [
                'jobs' => [
                    [
                        'id' => 70001,
                        'url' => 'https://remotive.com/jobs/70001',
                        'title' => 'Senior Backend Engineer',
                        'company_name' => 'Acme Corp',
                        'salary' => '$150,000 - $200,000',
                        'job_type' => 'full_time',
                        'candidate_required_location' => 'Worldwide',
                        'tags' => ['python'],
                        'publication_date' => '2026-05-10T10:00:00',
                        'description' => '<p>'.str_repeat('Hiring engineers. ', 20).'</p>',
                    ],
                ],
            ],
            [
                'jobs' => [
                    [
                        'id' => 70002,
                        'url' => 'https://remotive.com/jobs/70002',
                        'title' => 'ML Engineer',
                        'company_name' => 'Cyberdyne',
                        'salary' => null,
                        'job_type' => 'full_time',
                        'candidate_required_location' => 'EMEA',
                        'tags' => ['pytorch'],
                        'publication_date' => '2026-05-11T10:00:00',
                        'description' => '<p>'.str_repeat('AI research role. ', 20).'</p>',
                    ],
                ],
            ],
        ];

        foreach ($bodies as $idx => $body) {
            $day = $now->subDays($idx + 1);
            $path = "scrape-artifacts/{$day->toDateString()}/aggregator/remotive/orphan_{$idx}_aa.json.gz";
            $disk->put($path, gzencode((string) json_encode($body)));

            ScrapeRunArtifact::factory()->create([
                'tenant_id' => $this->tenant->id,
                'source_kind' => 'aggregator',
                'source_id' => $source->id,
                'connector' => 'remotive',
                'request_endpoint' => 'https://remotive.com/api/remote-jobs',
                'http_status' => 200,
                'path' => $path,
                'size_bytes' => 1024,
                'sha256' => hash('sha256', "{$idx}"),
                'retained_until' => $day->addDays(7),
                'fetched_at' => $day,
            ]);
        }

        $exit = Artisan::call('scrape:backfill', [
            '--tenant' => 'primary',
            '--source' => 'remotive',
            '--days' => '5',
        ]);

        $this->assertSame(0, $exit);

        $payload = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('remotive', $payload['source']);
        $this->assertSame(5, $payload['days_requested']);
        $this->assertGreaterThanOrEqual(2, $payload['days_processed']);
        $this->assertSame(2, $payload['new_listings']);

        $this->assertSame(2, AggregatorListing::query()->count());
    }

    #[Test]
    public function days_beyond_retention_window_reported_as_out_of_retention(): void
    {
        AggregatorSource::factory()->ofType(AggregatorSourceType::REMOTIVE)->create();

        $exit = Artisan::call('scrape:backfill', [
            '--tenant' => 'primary',
            '--source' => 'remotive',
            '--days' => '30',
        ]);

        $this->assertSame(0, $exit);

        $payload = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
        // Default retention is 7 days; 30 - 7 = 23 out of retention.
        $this->assertSame(23, $payload['out_of_retention']);
    }

    #[Test]
    public function remoteok_replay_skips_legal_envelope_at_index_zero(): void
    {
        $source = AggregatorSource::factory()->ofType(AggregatorSourceType::REMOTEOK)->create();
        $disk = $this->filesystem->disk('local');
        $now = CarbonImmutable::now();

        $body = [
            ['legal' => 'metadata', 'warning' => 'do-not-scrape'],
            [
                'id' => 'rok-1',
                'company' => 'Acme',
                'position' => 'Senior Engineer',
                'tags' => ['python'],
                'salary_min' => 150000,
                'salary_max' => 200000,
                'location' => 'Worldwide',
                'apply_url' => 'https://example.test/apply',
                'url' => 'https://remoteok.com/jobs/rok-1',
                'description' => str_repeat('We are hiring. ', 20),
                'date' => '2026-05-10T10:00:00Z',
            ],
        ];

        $day = $now->subDay();
        $path = "scrape-artifacts/{$day->toDateString()}/aggregator/remoteok/orphan_1_aa.json.gz";
        $disk->put($path, gzencode((string) json_encode($body)));

        ScrapeRunArtifact::factory()->create([
            'tenant_id' => $this->tenant->id,
            'source_kind' => 'aggregator',
            'source_id' => $source->id,
            'connector' => 'remoteok',
            'request_endpoint' => 'https://remoteok.com/api',
            'http_status' => 200,
            'path' => $path,
            'size_bytes' => 1024,
            'sha256' => hash('sha256', 'rok'),
            'retained_until' => $day->addDays(7),
            'fetched_at' => $day,
        ]);

        Artisan::call('scrape:backfill', [
            '--tenant' => 'primary',
            '--source' => 'remoteok',
            '--days' => '3',
        ]);

        $payload = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(1, $payload['new_listings']);
        $this->assertSame(1, AggregatorListing::query()->count());
    }

    #[Test]
    public function himalayas_replay_works_against_a_retained_artifact(): void
    {
        $source = AggregatorSource::factory()->ofType(AggregatorSourceType::HIMALAYAS)->create();
        $disk = $this->filesystem->disk('local');
        $now = CarbonImmutable::now();

        $body = [
            'jobs' => [
                [
                    'guid' => 'him-1',
                    'title' => 'Senior Backend Engineer',
                    'companyName' => 'Cloudflare',
                    'description' => '<p>'.str_repeat('Hiring engineers. ', 20).'</p>',
                    'publishedDate' => '2026-05-10T10:00:00Z',
                    'applicationLink' => 'https://himalayas.app/jobs/him-1',
                    'seniority' => ['senior'],
                    'employmentType' => ['full_time'],
                    'locationRestrictions' => [],
                    'timezoneRestrictions' => [],
                ],
            ],
        ];

        $day = $now->subDay();
        $path = "scrape-artifacts/{$day->toDateString()}/aggregator/himalayas/orphan_1_aa.json.gz";
        $disk->put($path, gzencode((string) json_encode($body)));

        ScrapeRunArtifact::factory()->create([
            'tenant_id' => $this->tenant->id,
            'source_kind' => 'aggregator',
            'source_id' => $source->id,
            'connector' => 'himalayas',
            'request_endpoint' => 'https://himalayas.app/api/jobs',
            'http_status' => 200,
            'path' => $path,
            'size_bytes' => 2048,
            'sha256' => hash('sha256', 'him'),
            'retained_until' => $day->addDays(7),
            'fetched_at' => $day,
        ]);

        Artisan::call('scrape:backfill', [
            '--tenant' => 'primary',
            '--source' => 'himalayas',
            '--days' => '2',
        ]);

        $payload = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(1, $payload['new_listings']);
        $this->assertSame(1, AggregatorListing::query()->where('external_id', 'him-1')->count());
    }

    #[Test]
    public function weworkremotely_replay_handles_rss_xml(): void
    {
        $source = AggregatorSource::factory()->ofType(AggregatorSourceType::WEWORKREMOTELY)->create();
        $disk = $this->filesystem->disk('local');
        $now = CarbonImmutable::now();

        $rss = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0"><channel>
  <title>WWR</title>
  <item>
    <guid>wwr-1</guid>
    <title>Acme: Senior Backend Engineer (Anywhere)</title>
    <link>https://weworkremotely.com/jobs/wwr-1</link>
    <region>Worldwide</region>
    <description><![CDATA[<p>We are hiring senior backend engineers. We need someone with Python, Postgres, and AWS experience. We are hiring senior backend engineers.</p>]]></description>
    <pubDate>Mon, 10 May 2026 10:00:00 +0000</pubDate>
  </item>
</channel></rss>
XML;

        $day = $now->subDay();
        $path = "scrape-artifacts/{$day->toDateString()}/aggregator/weworkremotely/orphan_1_aa.xml.gz";
        $disk->put($path, gzencode($rss));

        ScrapeRunArtifact::factory()->create([
            'tenant_id' => $this->tenant->id,
            'source_kind' => 'aggregator',
            'source_id' => $source->id,
            'connector' => 'weworkremotely',
            'request_endpoint' => 'https://weworkremotely.com/remote-back-end-programming-jobs.rss',
            'http_status' => 200,
            'path' => $path,
            'size_bytes' => 512,
            'sha256' => hash('sha256', 'wwr'),
            'retained_until' => $day->addDays(7),
            'fetched_at' => $day,
        ]);

        Artisan::call('scrape:backfill', [
            '--tenant' => 'primary',
            '--source' => 'weworkremotely',
            '--days' => '2',
        ]);

        $payload = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(1, $payload['new_listings']);
        $this->assertSame(1, AggregatorListing::query()->where('external_id', 'wwr-1')->count());
    }
}
