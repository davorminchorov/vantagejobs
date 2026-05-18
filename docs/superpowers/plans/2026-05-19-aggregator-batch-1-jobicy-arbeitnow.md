# Aggregator Batch 1 — Jobicy + Arbeitnow Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add two new aggregator sources — **Jobicy** (general remote, dev/tech filter, JSON API) and **Arbeitnow** (EU/general, JSON API) — to the existing aggregator pipeline.

**Architecture:** Each source follows the established `app/Scrapers/Aggregator/{Name}/` quartet — `{Name}Scraper` (orchestration), `{Name}Connector` (Saloon connector, config-driven timeouts), `{Name}ContextBuilder` (pure JSON→SourceContext mapping), `Requests/{Name}Request` (endpoint definition). They get registered in `AggregatorRegistry` via `AggregatorServiceProvider`, declared in `AggregatorSourceType`, and configured under `config/scrapers.php` → `aggregators.{name}`. The shared `AggregatorListingPersister` and `ParserPipeline` handle parsing/persistence; the scraper only owns fetch → filter → context build.

**Tech Stack:** Laravel 13, PHP 8.5, Saloon v3, PHPUnit 12, RefreshDatabase, Spin/Docker, Pint.

**Politeness:** Jobicy documents a 1-req/hr limit. We enforce this at the scheduler layer via `cadence_minutes = 60` (rather than client-side rate limiting); the scraper itself stays single-shot per run, matching Remotive's pattern. Arbeitnow has no published limit; default to 60 minutes to match.

**Scope boundary:** This plan covers Jobicy + Arbeitnow only. A sibling plan will cover tier 2 (Python.org / Django / Elixir Jobs / HexHire RSS feeds) once batch 1 lands and any pattern frictions surface.

---

## File Structure

**New files (per source — 4 prod + 1 test + 1 fixture):**

```
app/Scrapers/Aggregator/Jobicy/
  ├── JobicyConnector.php
  ├── JobicyContextBuilder.php
  ├── JobicyScraper.php
  └── Requests/RemoteJobsRequest.php

app/Scrapers/Aggregator/Arbeitnow/
  ├── ArbeitnowConnector.php
  ├── ArbeitnowContextBuilder.php
  ├── ArbeitnowScraper.php
  └── Requests/JobBoardRequest.php

tests/Feature/Scrapers/Aggregator/Jobicy/JobicyScraperTest.php
tests/Feature/Scrapers/Aggregator/Arbeitnow/ArbeitnowScraperTest.php
tests/Fixtures/scrapers/aggregator/jobicy/remote-jobs.json
tests/Fixtures/scrapers/aggregator/arbeitnow/job-board.json
```

**Modified files (shared wiring):**

```
app/Enums/AggregatorSourceType.php             # add JOBICY, ARBEITNOW + match arms
app/Providers/AggregatorServiceProvider.php    # register both scrapers
app/Scrapers/Aggregator/SourceContextFactory.php  # add re-parse cases
config/scrapers.php                            # aggregators.jobicy, aggregators.arbeitnow
```

**Notes on responsibility boundaries:**
- Scrapers stay thin — they call the connector, iterate the response array, hand each item to the context builder and the persister. No HTML parsing or salary math.
- Context builders are pure (no HTTP, no DB) so they can be replayed from `aggregator_listings.raw` by `SourceContextFactory`.
- All structured fields (companyName, title, tags, salary, location, jobType) flow through `SourceContext::structured` for the existing extractor pipeline to consume. We do not invent new extractors.

---

## Pre-implementation: capture real fixtures

Before any code is written, capture live fixture responses. The plan assumes specific JSON keys; whatever the live API returns is authoritative — adjust context builders to match the recorded payloads.

### Task 0: Record fixtures

**Files:**
- Create: `tests/Fixtures/scrapers/aggregator/jobicy/remote-jobs.json`
- Create: `tests/Fixtures/scrapers/aggregator/arbeitnow/job-board.json`

- [ ] **Step 1: Fetch Jobicy sample**

```bash
curl -sS 'https://jobicy.com/api/v2/remote-jobs?count=20&industry=dev' \
  -H 'User-Agent: VantageJobs/1.0 (+contact@vantagejobs.test)' \
  -H 'Accept: application/json' \
  | jq '.' > tests/Fixtures/scrapers/aggregator/jobicy/remote-jobs.json
```

Expected: file is a JSON object with a top-level `jobs` array containing ≥1 entry. Inspect the keys present on each job — note them for Task 4 (the context builder mapping).

- [ ] **Step 2: Trim the fixture to 3 representative jobs**

Edit `tests/Fixtures/scrapers/aggregator/jobicy/remote-jobs.json` so `jobs[]` contains exactly 3 entries that together cover: (a) a job with both `annualSalaryMin` and `annualSalaryMax` populated, (b) a job with `salaryCurrency` other than USD if available (else USD-only is fine), (c) a job with empty/missing salary fields. Preserve the original top-level envelope keys.

- [ ] **Step 3: Fetch Arbeitnow sample**

```bash
curl -sS 'https://www.arbeitnow.com/api/job-board-api' \
  -H 'User-Agent: VantageJobs/1.0 (+contact@vantagejobs.test)' \
  -H 'Accept: application/json' \
  | jq '.' > tests/Fixtures/scrapers/aggregator/arbeitnow/job-board.json
```

Expected: JSON object with `data[]` and `links`/`meta`. Inspect job-level keys.

- [ ] **Step 4: Trim Arbeitnow fixture to 3 representative jobs**

Edit so `data[]` has exactly 3 entries: (a) `remote: true`, (b) `remote: false` with a non-empty `location`, (c) one with non-empty `tags[]` and `job_types[]`. Keep `links`/`meta` as recorded.

- [ ] **Step 5: Commit fixtures**

```bash
git add tests/Fixtures/scrapers/aggregator/jobicy tests/Fixtures/scrapers/aggregator/arbeitnow
git commit -m "test: record Jobicy and Arbeitnow API fixtures"
```

---

## Shared wiring (do first, both scrapers depend on it)

### Task 1: Extend `AggregatorSourceType` enum

**Files:**
- Modify: `app/Enums/AggregatorSourceType.php`

- [ ] **Step 1: Add the new cases**

In `app/Enums/AggregatorSourceType.php`, add two cases after `LARAJOBS`:

```php
    case JOBICY = 'jobicy';
    case ARBEITNOW = 'arbeitnow';
```

- [ ] **Step 2: Extend `displayName()` match**

Add arms inside `displayName()`:

```php
            self::JOBICY => 'Jobicy',
            self::ARBEITNOW => 'Arbeitnow',
```

- [ ] **Step 3: Extend `defaultCadenceMinutes()` match**

Add `self::JOBICY` and `self::ARBEITNOW` to the existing `60 * 24` daily group. Jobicy's 1/hr documented limit is not the constraint — daily polling is more than within it.

```php
            self::REMOTIVE,
            self::HIMALAYAS,
            self::WEWORKREMOTELY,
            self::REMOTEOK,
            self::FOURDAYWEEK,
            self::WORKINGNOMADS,
            self::ADZUNA,
            self::LARAJOBS,
            self::JOBICY,
            self::ARBEITNOW => 60 * 24,
```

- [ ] **Step 4: Verify with a quick enum sanity check**

Run:

```bash
./vendor/bin/spin exec -T php php artisan tinker --execute 'echo App\Enums\AggregatorSourceType::JOBICY->displayName(); echo "|"; echo App\Enums\AggregatorSourceType::ARBEITNOW->defaultCadenceMinutes();'
```

Expected output: `Jobicy|1440`

- [ ] **Step 5: Commit**

```bash
git add app/Enums/AggregatorSourceType.php
git commit -m "feat: register Jobicy and Arbeitnow aggregator source types"
```

### Task 2: Add config entries

**Files:**
- Modify: `config/scrapers.php`

- [ ] **Step 1: Append the two aggregator config blocks**

Inside `'aggregators' => [...]`, after the `'adzuna'` block, add:

```php
        'jobicy' => [
            'concurrency' => 1,
            'timeout' => 15,
            'connect_timeout' => 5,
            'retry_times' => 3,
            'retry_sleep_ms' => 2000,
            // Jobicy documents a 1-req/hr poll limit; daily cadence
            // (defaultCadenceMinutes) keeps us well inside it.
        ],
        'arbeitnow' => [
            'concurrency' => 1,
            'timeout' => 15,
            'connect_timeout' => 5,
            'retry_times' => 3,
            'retry_sleep_ms' => 2000,
        ],
```

- [ ] **Step 2: Verify config loads**

```bash
./vendor/bin/spin exec -T php php artisan config:show scrapers.aggregators.jobicy
./vendor/bin/spin exec -T php php artisan config:show scrapers.aggregators.arbeitnow
```

Expected: each prints a populated array with the keys above.

- [ ] **Step 3: Commit**

```bash
git add config/scrapers.php
git commit -m "feat: add Jobicy and Arbeitnow scraper config"
```

---

## Jobicy scraper

### Task 3: Jobicy connector + request

**Files:**
- Create: `app/Scrapers/Aggregator/Jobicy/JobicyConnector.php`
- Create: `app/Scrapers/Aggregator/Jobicy/Requests/RemoteJobsRequest.php`

- [ ] **Step 1: Write the connector**

```php
<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\Jobicy;

use App\Scrapers\Aggregator\Artifacts\RecordResponseArtifact;
use Illuminate\Container\Container;
use Saloon\Http\Connector;
use Saloon\Http\PendingRequest;

/**
 * Saloon connector for jobicy.com's v2 remote-jobs JSON API.
 *
 * Anonymous GET. Jobicy publishes a 1-request-per-hour soft limit; daily
 * cadence (config('scrapers.aggregators.jobicy.concurrency') = 1,
 * AggregatorSourceType::JOBICY->defaultCadenceMinutes() = 1440) keeps us
 * well inside it.
 */
final class JobicyConnector extends Connector
{
    public function resolveBaseUrl(): string
    {
        return 'https://jobicy.com';
    }

    /**
     * @return array<string, string>
     */
    protected function defaultHeaders(): array
    {
        return [
            'User-Agent' => (string) config('scrapers.user_agent'),
            'Accept' => 'application/json',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultConfig(): array
    {
        return [
            'timeout' => (int) config('scrapers.aggregators.jobicy.timeout'),
            'connect_timeout' => (int) config('scrapers.aggregators.jobicy.connect_timeout'),
        ];
    }

    public function boot(PendingRequest $pendingRequest): void
    {
        $pendingRequest->middleware()->onResponse(
            Container::getInstance()->make(RecordResponseArtifact::class),
            'recordResponseArtifact',
        );
    }
}
```

- [ ] **Step 2: Write the request**

```php
<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\Jobicy\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * GET /api/v2/remote-jobs?count=50&industry=dev
 *
 * Returns a JSON envelope with `jobs[]`. We pin to `industry=dev` to scope
 * to the engineering subset of Jobicy's catalogue.
 */
final class RemoteJobsRequest extends Request
{
    protected Method $method = Method::GET;

    /**
     * @return array<string, string|int>
     */
    protected function defaultQuery(): array
    {
        return [
            'count' => 50,
            'industry' => 'dev',
        ];
    }

    public function resolveEndpoint(): string
    {
        return '/api/v2/remote-jobs';
    }
}
```

- [ ] **Step 3: Run Pint**

```bash
./vendor/bin/spin exec -T php ./vendor/bin/pint --dirty --format agent
```

Expected: clean — no changes or auto-fixed.

- [ ] **Step 4: Commit**

```bash
git add app/Scrapers/Aggregator/Jobicy/JobicyConnector.php app/Scrapers/Aggregator/Jobicy/Requests/RemoteJobsRequest.php
git commit -m "feat: add Jobicy Saloon connector and request"
```

### Task 4: Jobicy context builder

**Files:**
- Create: `app/Scrapers/Aggregator/Jobicy/JobicyContextBuilder.php`

The exact JSON keys come from the fixture recorded in Task 0. The mapping below uses the documented Jobicy v2 keys; if any key differs in the recorded fixture, adjust the property names here to match — the test in Task 6 will catch a mismatch immediately.

- [ ] **Step 1: Write the builder**

```php
<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\Jobicy;

use App\Enums\AggregatorSourceType;
use App\Scrapers\Aggregator\Parser\SourceContext;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Turns one Jobicy `jobs[]` record into a SourceContext.
 *
 * Pure — no HTTP, no DB. Jobicy ships structured JSON, so the structured
 * channel passes through largely as-is. We do:
 *
 *   - Strip HTML from `jobDescription` for bodyText (visa-sponsorship +
 *     body-fallback compensation extractors read it).
 *   - Parse `pubDate` as UTC.
 *   - Coerce salary fields to a numeric `compensation` sub-array so the
 *     existing CompensationExtractor picks them up via the `structured`
 *     channel.
 */
final class JobicyContextBuilder
{
    /**
     * @param  array<string, mixed>  $job
     */
    public function build(array $job): SourceContext
    {
        $descriptionHtml = (string) ($job['jobDescription'] ?? '');
        $bodyText = $this->stripHtml($descriptionHtml);

        $postedAt = null;
        $pubDate = $job['pubDate'] ?? null;
        if (is_string($pubDate) && $pubDate !== '') {
            try {
                $postedAt = CarbonImmutable::parse($pubDate, 'UTC');
            } catch (Throwable) {
                $postedAt = null;
            }
        }

        $externalId = isset($job['id']) ? (string) $job['id'] : null;
        $url = isset($job['url']) && is_string($job['url']) ? $job['url'] : null;

        $structured = $job;
        $structured['compensation'] = $this->extractCompensation($job);

        return new SourceContext(
            sourceType: AggregatorSourceType::JOBICY,
            structured: $structured,
            bodyText: $bodyText,
            bodyHtml: $descriptionHtml,
            postedAt: $postedAt,
            externalId: $externalId,
            url: $url,
        );
    }

    /**
     * @param  array<string, mixed>  $job
     * @return array{min: ?int, max: ?int, currency: ?string, period: ?string}
     */
    private function extractCompensation(array $job): array
    {
        $min = $job['salaryMin'] ?? null;
        $max = $job['salaryMax'] ?? null;
        $currency = $job['salaryCurrency'] ?? null;
        $period = $job['salaryPeriod'] ?? null;

        return [
            'min' => is_numeric($min) ? (int) $min : null,
            'max' => is_numeric($max) ? (int) $max : null,
            'currency' => is_string($currency) && $currency !== '' ? strtoupper($currency) : null,
            'period' => is_string($period) && $period !== '' ? strtolower($period) : null,
        ];
    }

    private function stripHtml(string $html): string
    {
        if ($html === '') {
            return '';
        }

        $decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $decoded = (string) preg_replace('/<\s*br\s*\/?\s*>/i', "\n", $decoded);
        $decoded = (string) preg_replace('/<\s*\/\s*(p|li|div|h\d)\s*>/i', "\n", $decoded);

        $stripped = strip_tags($decoded);
        $stripped = (string) preg_replace('/[ \t]+/', ' ', $stripped);
        $stripped = (string) preg_replace('/\n{3,}/', "\n\n", $stripped);

        return trim($stripped);
    }
}
```

- [ ] **Step 2: Run Pint**

```bash
./vendor/bin/spin exec -T php ./vendor/bin/pint --dirty --format agent
```

- [ ] **Step 3: Commit**

```bash
git add app/Scrapers/Aggregator/Jobicy/JobicyContextBuilder.php
git commit -m "feat: add Jobicy context builder"
```

### Task 5: Jobicy scraper

**Files:**
- Create: `app/Scrapers/Aggregator/Jobicy/JobicyScraper.php`

- [ ] **Step 1: Write the scraper (mirrors RemotiveScraper)**

```php
<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\Jobicy;

use App\Enums\AggregatorSourceType;
use App\Models\AggregatorSource;
use App\Scrapers\Aggregator\AggregatorListingPersister;
use App\Scrapers\Aggregator\AggregatorRunResult;
use App\Scrapers\Aggregator\Contracts\AggregatorScraperInterface;
use App\Scrapers\Aggregator\Jobicy\Requests\RemoteJobsRequest;
use Throwable;

/**
 * Jobicy remote-jobs JSON scraper.
 *
 *   - Single GET against /api/v2/remote-jobs returns up to `count` jobs.
 *   - external_id = the integer `id` field, stringified.
 *   - Politeness: Jobicy documents a 1-req/hr limit; daily cadence is well
 *     inside it. Concurrency 1 in config.
 */
final readonly class JobicyScraper implements AggregatorScraperInterface
{
    public function __construct(
        private JobicyContextBuilder $contextBuilder,
        private AggregatorListingPersister $persister,
    ) {}

    public function name(): AggregatorSourceType
    {
        return AggregatorSourceType::JOBICY;
    }

    public function concurrency(): int
    {
        return (int) config('scrapers.aggregators.jobicy.concurrency');
    }

    public function run(AggregatorSource $source): AggregatorRunResult
    {
        try {
            $response = (new JobicyConnector())->send(new RemoteJobsRequest());
            $payload = $response->json();
        } catch (Throwable $e) {
            return new AggregatorRunResult(
                fetched: 0,
                newCount: 0,
                errors: [['message' => $e->getMessage()]],
            );
        }

        $jobs = is_array($payload['jobs'] ?? null) ? $payload['jobs'] : [];

        $fetched = 0;
        $createdIds = [];
        $errors = [];

        foreach ($jobs as $job) {
            if (! is_array($job)) {
                continue;
            }
            $externalId = isset($job['id']) ? (string) $job['id'] : '';
            if ($externalId === '') {
                continue;
            }

            $fetched++;

            try {
                $context = $this->contextBuilder->build($job);
                $id = $this->persister->upsert($source, $context, $job, $externalId);
                if ($id !== null) {
                    $createdIds[] = $id;
                }
            } catch (Throwable $e) {
                $errors[] = [
                    'message' => $e->getMessage(),
                    'context' => ['job_id' => $externalId],
                ];
            }
        }

        $this->persister->capRun($createdIds);

        return new AggregatorRunResult(
            fetched: $fetched,
            newCount: count($createdIds),
            errors: $errors,
        );
    }
}
```

- [ ] **Step 2: Run Pint**

```bash
./vendor/bin/spin exec -T php ./vendor/bin/pint --dirty --format agent
```

- [ ] **Step 3: Commit**

```bash
git add app/Scrapers/Aggregator/Jobicy/JobicyScraper.php
git commit -m "feat: add Jobicy aggregator scraper"
```

### Task 6: Register Jobicy scraper

**Files:**
- Modify: `app/Providers/AggregatorServiceProvider.php`
- Modify: `app/Scrapers/Aggregator/SourceContextFactory.php`

- [ ] **Step 1: Add the import + registration in `AggregatorServiceProvider`**

In the `use` block, add (alphabetical with siblings):

```php
use App\Scrapers\Aggregator\Jobicy\JobicyScraper;
```

Inside `boot()`, after the existing `register(...LarajobsScraper...)` line, add:

```php
        $registry->register($this->app->make(JobicyScraper::class));
```

- [ ] **Step 2: Add re-parse support to `SourceContextFactory`**

In `app/Scrapers/Aggregator/SourceContextFactory.php`, add the import:

```php
use App\Scrapers\Aggregator\Jobicy\JobicyContextBuilder;
```

Add a constructor property:

```php
        private JobicyContextBuilder $jobicy,
```

Add a match arm inside `buildFor()`:

```php
            AggregatorSourceType::JOBICY => $this->jobicy->build($raw),
```

- [ ] **Step 3: Run Pint**

```bash
./vendor/bin/spin exec -T php ./vendor/bin/pint --dirty --format agent
```

- [ ] **Step 4: Verify registry resolution**

```bash
./vendor/bin/spin exec -T php php artisan tinker --execute '$r = app(App\Scrapers\Aggregator\AggregatorRegistry::class); var_dump($r->has(App\Enums\AggregatorSourceType::JOBICY));'
```

Expected: `bool(true)`

- [ ] **Step 5: Commit**

```bash
git add app/Providers/AggregatorServiceProvider.php app/Scrapers/Aggregator/SourceContextFactory.php
git commit -m "feat: register Jobicy scraper in aggregator registry"
```

### Task 7: Jobicy feature test

**Files:**
- Create: `tests/Feature/Scrapers/Aggregator/Jobicy/JobicyScraperTest.php`

This test mirrors `RemotiveScraperTest` exactly — same five scenarios (happy path, idempotency, status-preservation, empty payload, network failure). The assertions check fields produced by the shared parser pipeline, so they exercise the full integration.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Scrapers\Aggregator\Jobicy;

use App\Enums\AggregatorListingStatus;
use App\Enums\AggregatorSourceType;
use App\Models\AggregatorListing;
use App\Models\AggregatorSource;
use App\Scrapers\Aggregator\Jobicy\JobicyScraper;
use App\Scrapers\Aggregator\Jobicy\Requests\RemoteJobsRequest;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Laravel\Facades\Saloon;
use Tests\TestCase;

final class JobicyScraperTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private AggregatorSource $source;

    private JobicyScraper $scraper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->setCurrent($this->tenant);

        $this->source = AggregatorSource::factory()->ofType(AggregatorSourceType::JOBICY)->create();
        $this->scraper = $this->app->make(JobicyScraper::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(): array
    {
        $path = __DIR__.'/../../../../Fixtures/scrapers/aggregator/jobicy/remote-jobs.json';

        return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    #[Test]
    public function happy_path_persists_each_job_with_denormalized_fields(): void
    {
        Saloon::fake([
            RemoteJobsRequest::class => MockResponse::make($this->fixture(), 200),
        ]);

        $result = $this->scraper->run($this->source);

        $this->assertFalse($result->hasErrors());
        $this->assertSame(3, $result->fetched);
        $this->assertSame(3, $result->newCount);
        $this->assertSame(3, AggregatorListing::query()->count());

        // Every persisted row carries a non-empty external_id and company key.
        AggregatorListing::query()->get()->each(function (AggregatorListing $row): void {
            $this->assertNotEmpty($row->external_id);
            $this->assertNotEmpty($row->parsed_company_name);
            $this->assertNotEmpty($row->parsed_role_title);
            $this->assertSame(AggregatorListingStatus::NEW, $row->status);
        });
    }

    #[Test]
    public function rerunning_against_identical_payload_is_idempotent(): void
    {
        Saloon::fake([
            RemoteJobsRequest::class => MockResponse::make($this->fixture(), 200),
        ]);
        $first = $this->scraper->run($this->source);
        $this->assertSame(3, $first->newCount);

        Saloon::fake([
            RemoteJobsRequest::class => MockResponse::make($this->fixture(), 200),
        ]);
        $second = $this->scraper->run($this->source);

        $this->assertSame(3, $second->fetched);
        $this->assertSame(0, $second->newCount);
        $this->assertSame(3, AggregatorListing::query()->count());
    }

    #[Test]
    public function preserves_user_status_on_refetch(): void
    {
        Saloon::fake([
            RemoteJobsRequest::class => MockResponse::make($this->fixture(), 200),
        ]);
        $this->scraper->run($this->source);

        $row = AggregatorListing::query()->first();
        $row->status = AggregatorListingStatus::KEPT;
        $row->save();
        $externalId = $row->external_id;

        Saloon::fake([
            RemoteJobsRequest::class => MockResponse::make($this->fixture(), 200),
        ]);
        $this->scraper->run($this->source);

        $this->assertSame(
            AggregatorListingStatus::KEPT,
            AggregatorListing::query()->where('external_id', $externalId)->firstOrFail()->status,
        );
    }

    #[Test]
    public function empty_jobs_array_is_a_clean_no_op(): void
    {
        Saloon::fake([
            RemoteJobsRequest::class => MockResponse::make(['jobs' => []], 200),
        ]);

        $result = $this->scraper->run($this->source);

        $this->assertSame(0, $result->fetched);
        $this->assertSame(0, $result->newCount);
        $this->assertFalse($result->hasErrors());
        $this->assertSame(0, AggregatorListing::query()->count());
    }

    #[Test]
    public function network_exception_returns_errors_without_persisting(): void
    {
        Saloon::fake([
            RemoteJobsRequest::class => function (PendingRequest $request): MockResponse {
                throw new RuntimeException('jobicy 429');
            },
        ]);

        $result = $this->scraper->run($this->source);

        $this->assertTrue($result->hasErrors());
        $this->assertSame('jobicy 429', $result->errors[0]['message']);
        $this->assertSame(0, AggregatorListing::query()->count());
    }
}
```

- [ ] **Step 2: Run the test (should pass — implementation is already in place)**

```bash
./vendor/bin/spin exec -T php php artisan test --compact --filter=JobicyScraperTest
```

Expected: 5 passed.

If a test fails because a JSON key differs from the documented Jobicy v2 keys, adjust `JobicyContextBuilder` field names to match the recorded fixture and rerun. Do not adjust the fixture to match the code — the fixture is the source of truth.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Scrapers/Aggregator/Jobicy
git commit -m "test: feature-test Jobicy scraper end-to-end"
```

---

## Arbeitnow scraper

### Task 8: Arbeitnow connector + request

**Files:**
- Create: `app/Scrapers/Aggregator/Arbeitnow/ArbeitnowConnector.php`
- Create: `app/Scrapers/Aggregator/Arbeitnow/Requests/JobBoardRequest.php`

- [ ] **Step 1: Write the connector**

```php
<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\Arbeitnow;

use App\Scrapers\Aggregator\Artifacts\RecordResponseArtifact;
use Illuminate\Container\Container;
use Saloon\Http\Connector;
use Saloon\Http\PendingRequest;

/**
 * Saloon connector for arbeitnow.com's job-board JSON API.
 *
 * Anonymous GET. EU/Germany-focused. No published rate limit; we run daily
 * with concurrency 1 to stay polite.
 */
final class ArbeitnowConnector extends Connector
{
    public function resolveBaseUrl(): string
    {
        return 'https://www.arbeitnow.com';
    }

    /**
     * @return array<string, string>
     */
    protected function defaultHeaders(): array
    {
        return [
            'User-Agent' => (string) config('scrapers.user_agent'),
            'Accept' => 'application/json',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultConfig(): array
    {
        return [
            'timeout' => (int) config('scrapers.aggregators.arbeitnow.timeout'),
            'connect_timeout' => (int) config('scrapers.aggregators.arbeitnow.connect_timeout'),
        ];
    }

    public function boot(PendingRequest $pendingRequest): void
    {
        $pendingRequest->middleware()->onResponse(
            Container::getInstance()->make(RecordResponseArtifact::class),
            'recordResponseArtifact',
        );
    }
}
```

- [ ] **Step 2: Write the request**

```php
<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\Arbeitnow\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * GET /api/job-board-api
 *
 * Returns `data[]` with the latest job listings. Pagination is available
 * via `?page=N`; this first integration scrapes only page 1 (latest jobs)
 * and relies on daily cadence to keep up.
 */
final class JobBoardRequest extends Request
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/api/job-board-api';
    }
}
```

- [ ] **Step 3: Run Pint**

```bash
./vendor/bin/spin exec -T php ./vendor/bin/pint --dirty --format agent
```

- [ ] **Step 4: Commit**

```bash
git add app/Scrapers/Aggregator/Arbeitnow/ArbeitnowConnector.php app/Scrapers/Aggregator/Arbeitnow/Requests/JobBoardRequest.php
git commit -m "feat: add Arbeitnow Saloon connector and request"
```

### Task 9: Arbeitnow context builder

**Files:**
- Create: `app/Scrapers/Aggregator/Arbeitnow/ArbeitnowContextBuilder.php`

Verify field names against the fixture from Task 0 step 4. The documented Arbeitnow API exposes: `slug`, `company_name`, `title`, `description` (HTML), `remote` (bool), `url`, `tags[]`, `job_types[]`, `location`, `created_at` (unix timestamp).

- [ ] **Step 1: Write the builder**

```php
<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\Arbeitnow;

use App\Enums\AggregatorSourceType;
use App\Scrapers\Aggregator\Parser\SourceContext;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Turns one Arbeitnow `data[]` record into a SourceContext.
 *
 * Pure — no HTTP, no DB. Arbeitnow ships structured JSON; the structured
 * channel passes through, with HTML stripped from `description` for
 * bodyText, and `created_at` (unix seconds) parsed into a CarbonImmutable.
 */
final class ArbeitnowContextBuilder
{
    /**
     * @param  array<string, mixed>  $job
     */
    public function build(array $job): SourceContext
    {
        $descriptionHtml = (string) ($job['description'] ?? '');
        $bodyText = $this->stripHtml($descriptionHtml);

        $postedAt = null;
        $createdAt = $job['created_at'] ?? null;
        if (is_numeric($createdAt)) {
            try {
                $postedAt = CarbonImmutable::createFromTimestampUTC((int) $createdAt);
            } catch (Throwable) {
                $postedAt = null;
            }
        } elseif (is_string($createdAt) && $createdAt !== '') {
            try {
                $postedAt = CarbonImmutable::parse($createdAt, 'UTC');
            } catch (Throwable) {
                $postedAt = null;
            }
        }

        $externalId = isset($job['slug']) && is_string($job['slug']) ? $job['slug'] : null;
        $url = isset($job['url']) && is_string($job['url']) ? $job['url'] : null;

        return new SourceContext(
            sourceType: AggregatorSourceType::ARBEITNOW,
            structured: $job,
            bodyText: $bodyText,
            bodyHtml: $descriptionHtml,
            postedAt: $postedAt,
            externalId: $externalId,
            url: $url,
        );
    }

    private function stripHtml(string $html): string
    {
        if ($html === '') {
            return '';
        }

        $decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $decoded = (string) preg_replace('/<\s*br\s*\/?\s*>/i', "\n", $decoded);
        $decoded = (string) preg_replace('/<\s*\/\s*(p|li|div|h\d)\s*>/i', "\n", $decoded);

        $stripped = strip_tags($decoded);
        $stripped = (string) preg_replace('/[ \t]+/', ' ', $stripped);
        $stripped = (string) preg_replace('/\n{3,}/', "\n\n", $stripped);

        return trim($stripped);
    }
}
```

- [ ] **Step 2: Run Pint**

```bash
./vendor/bin/spin exec -T php ./vendor/bin/pint --dirty --format agent
```

- [ ] **Step 3: Commit**

```bash
git add app/Scrapers/Aggregator/Arbeitnow/ArbeitnowContextBuilder.php
git commit -m "feat: add Arbeitnow context builder"
```

### Task 10: Arbeitnow scraper

**Files:**
- Create: `app/Scrapers/Aggregator/Arbeitnow/ArbeitnowScraper.php`

- [ ] **Step 1: Write the scraper**

```php
<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\Arbeitnow;

use App\Enums\AggregatorSourceType;
use App\Models\AggregatorSource;
use App\Scrapers\Aggregator\AggregatorListingPersister;
use App\Scrapers\Aggregator\AggregatorRunResult;
use App\Scrapers\Aggregator\Arbeitnow\Requests\JobBoardRequest;
use App\Scrapers\Aggregator\Contracts\AggregatorScraperInterface;
use Throwable;

/**
 * Arbeitnow job-board JSON scraper.
 *
 *   - Single GET against /api/job-board-api returns the latest page of
 *     `data[]` records.
 *   - external_id = `slug` (canonical, present in the URL).
 *   - No published rate limit; daily cadence with concurrency 1.
 */
final readonly class ArbeitnowScraper implements AggregatorScraperInterface
{
    public function __construct(
        private ArbeitnowContextBuilder $contextBuilder,
        private AggregatorListingPersister $persister,
    ) {}

    public function name(): AggregatorSourceType
    {
        return AggregatorSourceType::ARBEITNOW;
    }

    public function concurrency(): int
    {
        return (int) config('scrapers.aggregators.arbeitnow.concurrency');
    }

    public function run(AggregatorSource $source): AggregatorRunResult
    {
        try {
            $response = (new ArbeitnowConnector())->send(new JobBoardRequest());
            $payload = $response->json();
        } catch (Throwable $e) {
            return new AggregatorRunResult(
                fetched: 0,
                newCount: 0,
                errors: [['message' => $e->getMessage()]],
            );
        }

        $jobs = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        $fetched = 0;
        $createdIds = [];
        $errors = [];

        foreach ($jobs as $job) {
            if (! is_array($job)) {
                continue;
            }
            $externalId = isset($job['slug']) && is_string($job['slug']) ? $job['slug'] : '';
            if ($externalId === '') {
                continue;
            }

            $fetched++;

            try {
                $context = $this->contextBuilder->build($job);
                $id = $this->persister->upsert($source, $context, $job, $externalId);
                if ($id !== null) {
                    $createdIds[] = $id;
                }
            } catch (Throwable $e) {
                $errors[] = [
                    'message' => $e->getMessage(),
                    'context' => ['slug' => $externalId],
                ];
            }
        }

        $this->persister->capRun($createdIds);

        return new AggregatorRunResult(
            fetched: $fetched,
            newCount: count($createdIds),
            errors: $errors,
        );
    }
}
```

- [ ] **Step 2: Run Pint**

```bash
./vendor/bin/spin exec -T php ./vendor/bin/pint --dirty --format agent
```

- [ ] **Step 3: Commit**

```bash
git add app/Scrapers/Aggregator/Arbeitnow/ArbeitnowScraper.php
git commit -m "feat: add Arbeitnow aggregator scraper"
```

### Task 11: Register Arbeitnow scraper

**Files:**
- Modify: `app/Providers/AggregatorServiceProvider.php`
- Modify: `app/Scrapers/Aggregator/SourceContextFactory.php`

- [ ] **Step 1: Add registration to `AggregatorServiceProvider`**

Add import:

```php
use App\Scrapers\Aggregator\Arbeitnow\ArbeitnowScraper;
```

In `boot()`, after the Jobicy line:

```php
        $registry->register($this->app->make(ArbeitnowScraper::class));
```

- [ ] **Step 2: Add re-parse support to `SourceContextFactory`**

Add import:

```php
use App\Scrapers\Aggregator\Arbeitnow\ArbeitnowContextBuilder;
```

Add constructor property:

```php
        private ArbeitnowContextBuilder $arbeitnow,
```

Add match arm:

```php
            AggregatorSourceType::ARBEITNOW => $this->arbeitnow->build($raw),
```

- [ ] **Step 3: Run Pint**

```bash
./vendor/bin/spin exec -T php ./vendor/bin/pint --dirty --format agent
```

- [ ] **Step 4: Verify**

```bash
./vendor/bin/spin exec -T php php artisan tinker --execute '$r = app(App\Scrapers\Aggregator\AggregatorRegistry::class); var_dump($r->has(App\Enums\AggregatorSourceType::ARBEITNOW));'
```

Expected: `bool(true)`

- [ ] **Step 5: Commit**

```bash
git add app/Providers/AggregatorServiceProvider.php app/Scrapers/Aggregator/SourceContextFactory.php
git commit -m "feat: register Arbeitnow scraper in aggregator registry"
```

### Task 12: Arbeitnow feature test

**Files:**
- Create: `tests/Feature/Scrapers/Aggregator/Arbeitnow/ArbeitnowScraperTest.php`

- [ ] **Step 1: Write the test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Scrapers\Aggregator\Arbeitnow;

use App\Enums\AggregatorListingStatus;
use App\Enums\AggregatorSourceType;
use App\Models\AggregatorListing;
use App\Models\AggregatorSource;
use App\Scrapers\Aggregator\Arbeitnow\ArbeitnowScraper;
use App\Scrapers\Aggregator\Arbeitnow\Requests\JobBoardRequest;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Laravel\Facades\Saloon;
use Tests\TestCase;

final class ArbeitnowScraperTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private AggregatorSource $source;

    private ArbeitnowScraper $scraper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->setCurrent($this->tenant);

        $this->source = AggregatorSource::factory()->ofType(AggregatorSourceType::ARBEITNOW)->create();
        $this->scraper = $this->app->make(ArbeitnowScraper::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(): array
    {
        $path = __DIR__.'/../../../../Fixtures/scrapers/aggregator/arbeitnow/job-board.json';

        return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    #[Test]
    public function happy_path_persists_each_job_with_denormalized_fields(): void
    {
        Saloon::fake([
            JobBoardRequest::class => MockResponse::make($this->fixture(), 200),
        ]);

        $result = $this->scraper->run($this->source);

        $this->assertFalse($result->hasErrors());
        $this->assertSame(3, $result->fetched);
        $this->assertSame(3, $result->newCount);
        $this->assertSame(3, AggregatorListing::query()->count());

        AggregatorListing::query()->get()->each(function (AggregatorListing $row): void {
            $this->assertNotEmpty($row->external_id);
            $this->assertNotEmpty($row->parsed_company_name);
            $this->assertNotEmpty($row->parsed_role_title);
            $this->assertSame(AggregatorListingStatus::NEW, $row->status);
        });
    }

    #[Test]
    public function rerunning_against_identical_payload_is_idempotent(): void
    {
        Saloon::fake([
            JobBoardRequest::class => MockResponse::make($this->fixture(), 200),
        ]);
        $first = $this->scraper->run($this->source);
        $this->assertSame(3, $first->newCount);

        Saloon::fake([
            JobBoardRequest::class => MockResponse::make($this->fixture(), 200),
        ]);
        $second = $this->scraper->run($this->source);

        $this->assertSame(3, $second->fetched);
        $this->assertSame(0, $second->newCount);
        $this->assertSame(3, AggregatorListing::query()->count());
    }

    #[Test]
    public function preserves_user_status_on_refetch(): void
    {
        Saloon::fake([
            JobBoardRequest::class => MockResponse::make($this->fixture(), 200),
        ]);
        $this->scraper->run($this->source);

        $row = AggregatorListing::query()->first();
        $row->status = AggregatorListingStatus::KEPT;
        $row->save();
        $externalId = $row->external_id;

        Saloon::fake([
            JobBoardRequest::class => MockResponse::make($this->fixture(), 200),
        ]);
        $this->scraper->run($this->source);

        $this->assertSame(
            AggregatorListingStatus::KEPT,
            AggregatorListing::query()->where('external_id', $externalId)->firstOrFail()->status,
        );
    }

    #[Test]
    public function empty_data_array_is_a_clean_no_op(): void
    {
        Saloon::fake([
            JobBoardRequest::class => MockResponse::make(['data' => [], 'links' => [], 'meta' => []], 200),
        ]);

        $result = $this->scraper->run($this->source);

        $this->assertSame(0, $result->fetched);
        $this->assertSame(0, $result->newCount);
        $this->assertFalse($result->hasErrors());
        $this->assertSame(0, AggregatorListing::query()->count());
    }

    #[Test]
    public function network_exception_returns_errors_without_persisting(): void
    {
        Saloon::fake([
            JobBoardRequest::class => function (PendingRequest $request): MockResponse {
                throw new RuntimeException('arbeitnow 500');
            },
        ]);

        $result = $this->scraper->run($this->source);

        $this->assertTrue($result->hasErrors());
        $this->assertSame('arbeitnow 500', $result->errors[0]['message']);
        $this->assertSame(0, AggregatorListing::query()->count());
    }
}
```

- [ ] **Step 2: Run the test**

```bash
./vendor/bin/spin exec -T php php artisan test --compact --filter=ArbeitnowScraperTest
```

Expected: 5 passed.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Scrapers/Aggregator/Arbeitnow
git commit -m "test: feature-test Arbeitnow scraper end-to-end"
```

---

## Final integration check

### Task 13: Run the full aggregator test suite

- [ ] **Step 1: Run all aggregator-related tests**

```bash
./vendor/bin/spin exec -T php php artisan test --compact tests/Feature/Scrapers/Aggregator
```

Expected: green across all existing aggregator tests plus the two new ones. If any pre-existing test regresses, the registry change in Task 6/11 broke something — inspect the failing test's resolution path.

- [ ] **Step 2: Run unit tests too (catches changes to context builders and source-context shape)**

```bash
./vendor/bin/spin exec -T php php artisan test --compact tests/Unit/Scrapers
```

Expected: green.

- [ ] **Step 3: Final Pint pass**

```bash
./vendor/bin/spin exec -T php ./vendor/bin/pint --dirty --format agent
```

Expected: nothing to change (every prior task already ran Pint).

- [ ] **Step 4: Optional smoke against live endpoints**

If credentials/network permit, seed a row and run the scrape command end-to-end:

```bash
./vendor/bin/spin exec -T php php artisan tinker --execute '
$tenant = App\Tenancy\Tenant::factory()->create();
app(App\Tenancy\TenantContext::class)->setCurrent($tenant);
$src = App\Models\AggregatorSource::factory()->ofType(App\Enums\AggregatorSourceType::JOBICY)->create();
$result = app(App\Scrapers\Aggregator\Jobicy\JobicyScraper::class)->run($src);
echo "fetched={$result->fetched} new={$result->newCount}";
'
```

Expected: non-zero `fetched` and `new` counts. Repeat with `ARBEITNOW` and `ArbeitnowScraper`. Note: this hits the real API — only run once during validation, not in a loop.

---

## Tier 2 follow-up (separate plan)

Once this batch lands, a follow-up plan will cover the RSS-based language-niche feeds:

- **Python.org Jobs** — `https://www.python.org/jobs/feed/rss/`
- **Django Job Board** — `https://jobs.django-news.com/feed/`
- **Elixir Jobs** — `https://elixirjobs.net/feed` (confirm exact URL on site)
- **HexHire** — `https://hexhire.io/feed` (confirm exact URL on site)

These will follow the **LarajobsScraper** pattern (RSS via SimpleXMLElement) rather than the Remotive JSON pattern. Defer wiring until batch 1 is in `main` so any pattern frictions surface and get fixed once.
