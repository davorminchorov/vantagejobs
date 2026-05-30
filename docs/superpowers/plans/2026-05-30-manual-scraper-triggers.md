# Manual Scraper Triggers Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add buttons on `/admin/runs` to manually queue scraper runs (everything, per ATS type, all aggregators, or one aggregator) with a live-updating runs table.

**Architecture:** Two thin queued jobs wrap the existing `ScrapeOrchestrator` / `AggregatorOrchestrator` (unchanged). A single `TriggerController` fans jobs out onto Horizon by `scope`. The Admin Runs `IndexController` gains `triggerOptions` + `activeRunCount` props; the React page gains a "Run scrapers" panel and Inertia `usePoll` polling that runs while any scrape is in flight.

**Tech Stack:** Laravel 13, Redis queue + Horizon, multi-tenant via `TenantContext` + `BelongsToTenant` global scope, Inertia v2 + React 19, PHPUnit. All PHP/test commands run through `spin`.

---

## Conventions for every task

- Every PHP file starts with `declare(strict_types=1);`.
- Run tests with the running dev stack: `spin exec -T php php artisan test --compact --filter=<Name>`. If the stack is not up, substitute `spin run -T php php artisan test ...`.
- Format touched PHP before committing: `spin run -T php ./vendor/bin/pint --dirty --format agent`.
- The project uses **pnpm**, never npm.
- Models are tenant-scoped: any DB read inside a job must happen *after* `TenantContext::setCurrent()`.

## File structure

- Create: `app/Jobs/RunCompanyScrapeJob.php` — queue one ATS company scrape.
- Create: `app/Jobs/RunAggregatorScrapeJob.php` — queue one aggregator source scrape.
- Create: `app/Http/Controllers/Admin/Runs/TriggerScrapersRequest.php` — validates `scope`.
- Create: `app/Http/Controllers/Admin/Runs/TriggerController.php` — fans out jobs.
- Modify: `routes/web.php` — add `POST /admin/runs/trigger`.
- Modify: `app/Http/Controllers/Admin/Runs/IndexController.php` — add `triggerOptions` + `activeRunCount` props.
- Modify: `resources/js/Pages/Admin/Runs/Index.tsx` — "Run scrapers" panel + polling + flash banner.
- Create tests: `tests/Feature/Jobs/RunCompanyScrapeJobTest.php`, `tests/Feature/Jobs/RunAggregatorScrapeJobTest.php`, `tests/Feature/Admin/Runs/TriggerTest.php`, and additions to `tests/Feature/Admin/Runs/IndexTest.php`.

---

## Task 1: `RunCompanyScrapeJob`

**Files:**
- Create: `app/Jobs/RunCompanyScrapeJob.php`
- Test: `tests/Feature/Jobs/RunCompanyScrapeJobTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Jobs/RunCompanyScrapeJobTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Enums\AtsType;
use App\Jobs\RunCompanyScrapeJob;
use App\Models\Company;
use App\Models\ScrapeRun;
use App\Scrapers\Contracts\AtsScraperInterface;
use App\Scrapers\ScrapeOrchestrator;
use App\Scrapers\ScraperRegistry;
use App\Scrapers\ScraperRunResult;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class RunCompanyScrapeJobTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create(['slug' => 'primary']);
        app(TenantContext::class)->setCurrent($this->tenant);
    }

    #[Test]
    public function it_runs_the_orchestrator_and_records_a_scrape_run(): void
    {
        $this->fakeScraper(AtsType::GREENHOUSE, new ScraperRunResult(fetched: 4, newCount: 2, closedCount: 0));
        $company = Company::factory()->create(['ats_type' => 'greenhouse', 'ats_slug' => 'acme']);

        // Simulate the worker: context is empty until the job sets it.
        app(TenantContext::class)->setCurrent(null);

        (new RunCompanyScrapeJob($this->tenant->id, $company->id))
            ->handle(app(TenantContext::class), app(ScrapeOrchestrator::class));

        app(TenantContext::class)->setCurrent($this->tenant);
        $this->assertSame(1, ScrapeRun::query()->count());
        $run = ScrapeRun::query()->sole();
        $this->assertSame('completed', $run->status);
        $this->assertSame(2, $run->new_count);
        $this->assertSame($this->tenant->id, (int) $run->tenant_id);
    }

    #[Test]
    public function it_is_a_safe_no_op_when_the_company_is_missing(): void
    {
        app(TenantContext::class)->setCurrent(null);

        (new RunCompanyScrapeJob($this->tenant->id, 999999))
            ->handle(app(TenantContext::class), app(ScrapeOrchestrator::class));

        app(TenantContext::class)->setCurrent($this->tenant);
        $this->assertSame(0, ScrapeRun::query()->count());
    }

    private function fakeScraper(AtsType $type, ScraperRunResult $result): void
    {
        /** @var ScraperRegistry $registry */
        $registry = $this->app->make(ScraperRegistry::class);
        $registry->register(new class($type, $result) implements AtsScraperInterface
        {
            public function __construct(private AtsType $type, private ScraperRunResult $result) {}

            public function name(): AtsType
            {
                return $this->type;
            }

            public function concurrency(): int
            {
                return 1;
            }

            public function run(Company $company): ScraperRunResult
            {
                return $this->result;
            }
        });
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `spin exec -T php php artisan test --compact --filter=RunCompanyScrapeJobTest`
Expected: FAIL — class `App\Jobs\RunCompanyScrapeJob` not found.

- [ ] **Step 3: Create the job**

Create `app/Jobs/RunCompanyScrapeJob.php`:

```php
<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Company;
use App\Scrapers\ScrapeOrchestrator;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Queues a single company's ATS scrape. Re-establishes tenant context before
 * any DB access (workers are long-lived and start with no context), then hands
 * off to the orchestrator, which opens and finalizes the scrape_runs row.
 *
 * ShouldBeUnique keeps a second click for the same company from double-running
 * while the first is still in flight.
 */
final class RunCompanyScrapeJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 1800;

    public function __construct(
        public int $tenantId,
        public int $companyId,
    ) {}

    public function handle(TenantContext $context, ScrapeOrchestrator $orchestrator): void
    {
        $tenant = Tenant::query()->find($this->tenantId);
        if (! $tenant instanceof Tenant) {
            return;
        }

        $context->setCurrent($tenant);

        $company = Company::query()->find($this->companyId);
        if (! $company instanceof Company) {
            return;
        }

        $orchestrator->runForCompany($company);
    }

    public function uniqueId(): string
    {
        return "run-company-scrape:{$this->tenantId}:{$this->companyId}";
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `spin exec -T php php artisan test --compact --filter=RunCompanyScrapeJobTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Format and commit**

```bash
spin run -T php ./vendor/bin/pint --dirty --format agent
git add app/Jobs/RunCompanyScrapeJob.php tests/Feature/Jobs/RunCompanyScrapeJobTest.php
git commit -m "feat: queued job to run one company ATS scrape"
```

---

## Task 2: `RunAggregatorScrapeJob`

**Files:**
- Create: `app/Jobs/RunAggregatorScrapeJob.php`
- Test: `tests/Feature/Jobs/RunAggregatorScrapeJobTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Jobs/RunAggregatorScrapeJobTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Enums\AggregatorSourceType;
use App\Jobs\RunAggregatorScrapeJob;
use App\Models\AggregatorSource;
use App\Models\ScrapeRun;
use App\Scrapers\Aggregator\AggregatorOrchestrator;
use App\Scrapers\Aggregator\AggregatorRegistry;
use App\Scrapers\Aggregator\AggregatorRunResult;
use App\Scrapers\Aggregator\Contracts\AggregatorScraperInterface;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class RunAggregatorScrapeJobTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create(['slug' => 'primary']);
        app(TenantContext::class)->setCurrent($this->tenant);
    }

    #[Test]
    public function it_runs_the_orchestrator_and_records_a_scrape_run(): void
    {
        $this->fakeScraper(AggregatorSourceType::REMOTIVE, new AggregatorRunResult(fetched: 6, newCount: 3));
        $source = AggregatorSource::factory()->ofType(AggregatorSourceType::REMOTIVE)->create();

        app(TenantContext::class)->setCurrent(null);

        (new RunAggregatorScrapeJob($this->tenant->id, $source->id))
            ->handle(app(TenantContext::class), app(AggregatorOrchestrator::class));

        app(TenantContext::class)->setCurrent($this->tenant);
        $this->assertSame(1, ScrapeRun::query()->count());
        $run = ScrapeRun::query()->sole();
        $this->assertSame('completed', $run->status);
        $this->assertSame('aggregator', $run->source_kind);
        $this->assertSame(3, $run->new_count);
    }

    #[Test]
    public function it_is_a_safe_no_op_when_the_source_is_missing(): void
    {
        app(TenantContext::class)->setCurrent(null);

        (new RunAggregatorScrapeJob($this->tenant->id, 999999))
            ->handle(app(TenantContext::class), app(AggregatorOrchestrator::class));

        app(TenantContext::class)->setCurrent($this->tenant);
        $this->assertSame(0, ScrapeRun::query()->count());
    }

    private function fakeScraper(AggregatorSourceType $type, AggregatorRunResult $result): void
    {
        /** @var AggregatorRegistry $registry */
        $registry = $this->app->make(AggregatorRegistry::class);
        $registry->register(new class($type, $result) implements AggregatorScraperInterface
        {
            public function __construct(private AggregatorSourceType $type, private AggregatorRunResult $result) {}

            public function name(): AggregatorSourceType
            {
                return $this->type;
            }

            public function concurrency(): int
            {
                return 1;
            }

            public function run(AggregatorSource $source): AggregatorRunResult
            {
                return $this->result;
            }
        });
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `spin exec -T php php artisan test --compact --filter=RunAggregatorScrapeJobTest`
Expected: FAIL — class `App\Jobs\RunAggregatorScrapeJob` not found.

- [ ] **Step 3: Create the job**

Create `app/Jobs/RunAggregatorScrapeJob.php`:

```php
<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AggregatorSource;
use App\Scrapers\Aggregator\AggregatorOrchestrator;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Queues a single aggregator source scrape. Mirrors RunCompanyScrapeJob: sets
 * tenant context, then hands off to the aggregator orchestrator, which owns the
 * scrape_runs row and the source's run-state bookkeeping.
 */
final class RunAggregatorScrapeJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 1800;

    public function __construct(
        public int $tenantId,
        public int $sourceId,
    ) {}

    public function handle(TenantContext $context, AggregatorOrchestrator $orchestrator): void
    {
        $tenant = Tenant::query()->find($this->tenantId);
        if (! $tenant instanceof Tenant) {
            return;
        }

        $context->setCurrent($tenant);

        $source = AggregatorSource::query()->find($this->sourceId);
        if (! $source instanceof AggregatorSource) {
            return;
        }

        $orchestrator->runForSource($source);
    }

    public function uniqueId(): string
    {
        return "run-aggregator-scrape:{$this->tenantId}:{$this->sourceId}";
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `spin exec -T php php artisan test --compact --filter=RunAggregatorScrapeJobTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Format and commit**

```bash
spin run -T php ./vendor/bin/pint --dirty --format agent
git add app/Jobs/RunAggregatorScrapeJob.php tests/Feature/Jobs/RunAggregatorScrapeJobTest.php
git commit -m "feat: queued job to run one aggregator source scrape"
```

---

## Task 3: Trigger controller + request + route

**Files:**
- Create: `app/Http/Controllers/Admin/Runs/TriggerScrapersRequest.php`
- Create: `app/Http/Controllers/Admin/Runs/TriggerController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Admin/Runs/TriggerTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Admin/Runs/TriggerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Runs;

use App\Enums\AggregatorSourceType;
use App\Jobs\RunAggregatorScrapeJob;
use App\Jobs\RunCompanyScrapeJob;
use App\Models\AggregatorSource;
use App\Models\Company;
use App\Models\User;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class TriggerTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->setCurrent($this->tenant);
        $this->user = User::factory()->create();
    }

    #[Test]
    public function guests_are_redirected_to_login(): void
    {
        $this->post('/admin/runs/trigger', ['scope' => 'all'])->assertRedirect('/login');
    }

    #[Test]
    public function invalid_scope_fails_validation(): void
    {
        $this->actingAs($this->user)
            ->from('/admin/runs')
            ->post('/admin/runs/trigger', ['scope' => 'bogus'])
            ->assertRedirect('/admin/runs')
            ->assertSessionHasErrors('scope');
    }

    #[Test]
    public function scope_ats_dispatches_one_job_per_ats_company(): void
    {
        Queue::fake();
        Company::factory()->create(['ats_type' => 'greenhouse', 'ats_slug' => 'gh-a']);
        Company::factory()->create(['ats_type' => 'lever', 'ats_slug' => 'lv-a']);
        Company::factory()->create(['ats_type' => null, 'ats_slug' => null]);

        $this->actingAs($this->user)->post('/admin/runs/trigger', ['scope' => 'ats']);

        Queue::assertPushed(RunCompanyScrapeJob::class, 2);
        Queue::assertNotPushed(RunAggregatorScrapeJob::class);
    }

    #[Test]
    public function scope_ats_type_dispatches_only_that_type(): void
    {
        Queue::fake();
        Company::factory()->create(['ats_type' => 'greenhouse', 'ats_slug' => 'gh-a']);
        Company::factory()->create(['ats_type' => 'greenhouse', 'ats_slug' => 'gh-b']);
        Company::factory()->create(['ats_type' => 'lever', 'ats_slug' => 'lv-a']);

        $this->actingAs($this->user)->post('/admin/runs/trigger', ['scope' => 'ats:greenhouse']);

        Queue::assertPushed(RunCompanyScrapeJob::class, 2);
    }

    #[Test]
    public function scope_aggregators_dispatches_only_enabled_sources(): void
    {
        Queue::fake();
        AggregatorSource::factory()->ofType(AggregatorSourceType::REMOTIVE)->create(['enabled' => true]);
        AggregatorSource::factory()->ofType(AggregatorSourceType::HIMALAYAS)->create(['enabled' => true]);
        AggregatorSource::factory()->ofType(AggregatorSourceType::JOBICY)->create(['enabled' => false]);

        $this->actingAs($this->user)->post('/admin/runs/trigger', ['scope' => 'aggregators']);

        Queue::assertPushed(RunAggregatorScrapeJob::class, 2);
        Queue::assertNotPushed(RunCompanyScrapeJob::class);
    }

    #[Test]
    public function scope_single_aggregator_dispatches_one_job_even_if_disabled(): void
    {
        Queue::fake();
        AggregatorSource::factory()->ofType(AggregatorSourceType::JOBICY)->create(['enabled' => false]);

        $this->actingAs($this->user)->post('/admin/runs/trigger', ['scope' => 'aggregator:jobicy']);

        Queue::assertPushed(RunAggregatorScrapeJob::class, 1);
    }

    #[Test]
    public function scope_all_dispatches_ats_companies_and_enabled_aggregators(): void
    {
        Queue::fake();
        Company::factory()->create(['ats_type' => 'greenhouse', 'ats_slug' => 'gh-a']);
        AggregatorSource::factory()->ofType(AggregatorSourceType::REMOTIVE)->create(['enabled' => true]);

        $this->actingAs($this->user)->post('/admin/runs/trigger', ['scope' => 'all']);

        Queue::assertPushed(RunCompanyScrapeJob::class, 1);
        Queue::assertPushed(RunAggregatorScrapeJob::class, 1);
    }

    #[Test]
    public function empty_scope_target_dispatches_nothing_and_flashes(): void
    {
        Queue::fake();

        $this->actingAs($this->user)
            ->post('/admin/runs/trigger', ['scope' => 'ats'])
            ->assertSessionHas('success', 'Nothing to run for that selection.');

        Queue::assertNothingPushed();
    }

    #[Test]
    public function it_does_not_dispatch_other_tenants_companies(): void
    {
        Queue::fake();
        Company::factory()->create(['ats_type' => 'greenhouse', 'ats_slug' => 'mine']);

        $other = Tenant::factory()->create();
        app(TenantContext::class)->withTenant($other, function (): void {
            Company::factory()->create(['ats_type' => 'greenhouse', 'ats_slug' => 'theirs']);
        });

        $this->actingAs($this->user)->post('/admin/runs/trigger', ['scope' => 'ats']);

        Queue::assertPushed(RunCompanyScrapeJob::class, 1);
    }
}
```

> Note: the acting user belongs to `$this->tenant` because `UserFactory::definition()` reads the current tenant from `TenantContext`, and `setUp()` sets that context before creating `$this->user`. At request time `ResolveTenantContext` re-reads `user.tenant_id` to restore the context. No extra wiring needed.

- [ ] **Step 2: Run test to verify it fails**

Run: `spin exec -T php php artisan test --compact --filter=TriggerTest`
Expected: FAIL — route `/admin/runs/trigger` not defined / controller missing.

- [ ] **Step 3: Create the form request**

Create `app/Http/Controllers/Admin/Runs/TriggerScrapersRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Runs;

use App\Enums\AggregatorSourceType;
use App\Enums\AtsType;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

final class TriggerScrapersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'scope' => [
                'required',
                'string',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value) || ! $this->scopeIsValid($value)) {
                        $fail('The selected scope is invalid.');
                    }
                },
            ],
        ];
    }

    public function scope(): string
    {
        return (string) $this->validated('scope');
    }

    private function scopeIsValid(string $scope): bool
    {
        if (in_array($scope, ['all', 'ats', 'aggregators'], true)) {
            return true;
        }

        if (str_starts_with($scope, 'ats:')) {
            return AtsType::tryFrom(substr($scope, 4)) instanceof AtsType;
        }

        if (str_starts_with($scope, 'aggregator:')) {
            return AggregatorSourceType::tryFrom(substr($scope, 11)) instanceof AggregatorSourceType;
        }

        return false;
    }
}
```

- [ ] **Step 4: Create the controller**

Create `app/Http/Controllers/Admin/Runs/TriggerController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Runs;

use App\Enums\AggregatorSourceType;
use App\Enums\AtsType;
use App\Jobs\RunAggregatorScrapeJob;
use App\Jobs\RunCompanyScrapeJob;
use App\Models\AggregatorSource;
use App\Models\Company;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;

/**
 * POST /admin/runs/trigger — queue scraper runs on demand.
 *
 * scope values:
 *   all                  every ATS-configured company + every enabled aggregator
 *   ats                  every ATS-configured company
 *   ats:{type}           companies on one ATS platform
 *   aggregators          every enabled aggregator source
 *   aggregator:{type}    one aggregator source (runs even if disabled)
 *
 * Queries are tenant-scoped by the global scope, so a trigger can only ever
 * dispatch the current tenant's targets. Each job opens its own scrape_runs
 * row; the page polls those rows for live progress.
 */
final readonly class TriggerController
{
    public function __construct(private TenantContext $context) {}

    public function __invoke(TriggerScrapersRequest $request): RedirectResponse
    {
        $tenantId = (int) $this->context->currentOrFail()->id;
        $scope = $request->scope();

        $dispatched = match (true) {
            $scope === 'all' => $this->dispatchAts($tenantId, null) + $this->dispatchAllAggregators($tenantId),
            $scope === 'ats' => $this->dispatchAts($tenantId, null),
            str_starts_with($scope, 'ats:') => $this->dispatchAts($tenantId, AtsType::from(substr($scope, 4))),
            $scope === 'aggregators' => $this->dispatchAllAggregators($tenantId),
            str_starts_with($scope, 'aggregator:') => $this->dispatchOneAggregator($tenantId, AggregatorSourceType::from(substr($scope, 11))),
            default => 0,
        };

        if ($dispatched === 0) {
            return back()->with('success', 'Nothing to run for that selection.');
        }

        $noun = $dispatched === 1 ? 'run' : 'runs';

        return back()->with('success', "Queued {$dispatched} scraper {$noun}.");
    }

    private function dispatchAts(int $tenantId, ?AtsType $type): int
    {
        $query = Company::query()
            ->whereNotNull('ats_type')
            ->whereNotNull('ats_slug');

        if ($type instanceof AtsType) {
            $query->where('ats_type', $type->value);
        }

        $ids = $query->orderBy('id')->pluck('id');

        foreach ($ids as $id) {
            RunCompanyScrapeJob::dispatch($tenantId, (int) $id);
        }

        return $ids->count();
    }

    private function dispatchAllAggregators(int $tenantId): int
    {
        $ids = AggregatorSource::query()
            ->where('enabled', true)
            ->orderBy('type')
            ->pluck('id');

        foreach ($ids as $id) {
            RunAggregatorScrapeJob::dispatch($tenantId, (int) $id);
        }

        return $ids->count();
    }

    private function dispatchOneAggregator(int $tenantId, AggregatorSourceType $type): int
    {
        $source = AggregatorSource::query()->where('type', $type->value)->first();

        if (! $source instanceof AggregatorSource) {
            return 0;
        }

        RunAggregatorScrapeJob::dispatch($tenantId, (int) $source->id);

        return 1;
    }
}
```

- [ ] **Step 5: Register the route**

In `routes/web.php`, add the import alongside the other `Admin\Runs` imports near the top:

```php
use App\Http\Controllers\Admin\Runs\TriggerController;
```

Then inside the existing `Route::prefix('admin')->name('admin.')->group(...)`, add the trigger route next to the runs routes (it must come before the `/runs/{run}` wildcard so it is not captured by it):

```php
Route::get('/runs', AdminRunsIndexController::class)->name('runs.index');
Route::post('/runs/trigger', TriggerController::class)->name('runs.trigger');
Route::get('/runs/artifacts/{artifact}/download', DownloadArtifactController::class)
    ->whereNumber('artifact')
    ->name('runs.artifacts.download');
Route::get('/runs/{run}', AdminRunsShowController::class)
    ->whereNumber('run')
    ->name('runs.show');
```

(The `{run}` route already has `->whereNumber('run')`, so `trigger` would not collide either way, but ordering it first is clearest.)

- [ ] **Step 6: Run test to verify it passes**

Run: `spin exec -T php php artisan test --compact --filter=TriggerTest`
Expected: PASS (8 tests). If `UserFactory` does not attach the tenant, apply the fix noted in Step 1.

- [ ] **Step 7: Format and commit**

```bash
spin run -T php ./vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/Admin/Runs/TriggerController.php app/Http/Controllers/Admin/Runs/TriggerScrapersRequest.php routes/web.php tests/Feature/Admin/Runs/TriggerTest.php
git commit -m "feat: POST /admin/runs/trigger to fan out scraper jobs by scope"
```

---

## Task 4: Add `triggerOptions` + `activeRunCount` props to the runs index

**Files:**
- Modify: `app/Http/Controllers/Admin/Runs/IndexController.php`
- Test: `tests/Feature/Admin/Runs/IndexTest.php` (add cases)

- [ ] **Step 1: Write the failing test**

Add these two methods to `tests/Feature/Admin/Runs/IndexTest.php` (the file already imports `AtsType`? No — add `use App\Enums\AtsType;` to the imports if missing; `Company`, `AggregatorSource`, `ScrapeRun`, `CarbonImmutable` are already imported):

```php
    #[Test]
    public function it_exposes_trigger_options_and_active_run_count(): void
    {
        Company::factory()->create(['ats_type' => 'greenhouse', 'ats_slug' => 'gh-a']);
        Company::factory()->create(['ats_type' => 'greenhouse', 'ats_slug' => 'gh-b']);
        Company::factory()->create(['ats_type' => null, 'ats_slug' => null]);
        AggregatorSource::factory()->ofType(\App\Enums\AggregatorSourceType::REMOTIVE)->create();

        ScrapeRun::factory()->create([
            'status' => 'running',
            'started_at' => CarbonImmutable::now()->subMinutes(2),
            'finished_at' => null,
        ]);
        // Stale running row (older than 1h) must not be counted.
        ScrapeRun::factory()->create([
            'status' => 'running',
            'started_at' => CarbonImmutable::now()->subHours(3),
            'finished_at' => null,
        ]);

        $response = $this->actingAs($this->user)->get('/admin/runs');
        $response->assertOk();
        $props = $response->viewData('page')['props'];

        $this->assertSame(1, $props['activeRunCount']);

        $atsTypes = collect($props['triggerOptions']['ats_types']);
        $greenhouse = $atsTypes->firstWhere('value', 'greenhouse');
        $this->assertNotNull($greenhouse);
        $this->assertSame(2, $greenhouse['count']);
        // A type with zero companies (e.g. lever) is omitted.
        $this->assertNull($atsTypes->firstWhere('value', 'lever'));

        $aggregators = collect($props['triggerOptions']['aggregators']);
        $this->assertNotNull($aggregators->firstWhere('value', 'remotive'));
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `spin exec -T php php artisan test --compact --filter="IndexTest::it_exposes_trigger_options_and_active_run_count"`
Expected: FAIL — undefined array key `activeRunCount` / `triggerOptions`.

- [ ] **Step 3: Implement the props**

In `app/Http/Controllers/Admin/Runs/IndexController.php`, add these imports if not already present:

```php
use App\Enums\AtsType;
```

(`AggregatorSourceType`, `AggregatorSource`, `Company`, `ScrapeRun`, `CarbonImmutable` are already imported.)

Add the two keys to the `Inertia::render('Admin/Runs/Index', [...])` array, after `'health' => $this->sourceHealth(),`:

```php
            'health' => $this->sourceHealth(),
            'triggerOptions' => $this->triggerOptions(),
            'activeRunCount' => $this->activeRunCount(),
```

Add these two private methods to the class (e.g. after `sourceHealth()`):

```php
    /**
     * Targets available for manual triggering: ATS types that have at least one
     * configured company (with a count), plus every aggregator source.
     *
     * @return array{
     *     ats_types: list<array{value: string, label: string, count: int}>,
     *     aggregators: list<array{value: string, label: string}>
     * }
     */
    private function triggerOptions(): array
    {
        $atsCounts = Company::query()
            ->whereNotNull('ats_type')
            ->whereNotNull('ats_slug')
            ->selectRaw('ats_type, COUNT(*) AS company_count')
            ->groupBy('ats_type')
            ->pluck('company_count', 'ats_type');

        $atsTypes = [];
        foreach (AtsType::cases() as $type) {
            $count = (int) ($atsCounts[$type->value] ?? 0);
            if ($count === 0) {
                continue;
            }
            $atsTypes[] = [
                'value' => $type->value,
                'label' => ucfirst($type->value),
                'count' => $count,
            ];
        }

        $aggregators = AggregatorSource::query()
            ->orderBy('name')
            ->get(['type', 'name'])
            ->map(static fn (AggregatorSource $s): array => [
                'value' => $s->type->value,
                'label' => (string) $s->name,
            ])
            ->all();

        return [
            'ats_types' => $atsTypes,
            'aggregators' => $aggregators,
        ];
    }

    /**
     * Count of scrape_runs currently in flight (status=running) and started
     * within the last hour, for the current tenant. Drives the live-poll
     * indicator on the page.
     */
    private function activeRunCount(): int
    {
        return ScrapeRun::query()
            ->where('status', 'running')
            ->where('started_at', '>=', CarbonImmutable::now()->subHour())
            ->count();
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `spin exec -T php php artisan test --compact --filter=IndexTest`
Expected: PASS (existing cases + the new one).

- [ ] **Step 5: Format and commit**

```bash
spin run -T php ./vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/Admin/Runs/IndexController.php tests/Feature/Admin/Runs/IndexTest.php
git commit -m "feat: expose trigger options and active run count on /admin/runs"
```

---

## Task 5: "Run scrapers" panel + live polling (frontend)

**Files:**
- Modify: `resources/js/Pages/Admin/Runs/Index.tsx`

No JS test runner is configured, so verification is a type-check/build plus a manual smoke test.

- [ ] **Step 1: Update imports**

Change the top imports of `resources/js/Pages/Admin/Runs/Index.tsx` from:

```tsx
import { Head, Link, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';
```

to:

```tsx
import { Head, Link, router, usePage, usePoll } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
```

- [ ] **Step 2: Extend the Props interface**

Add these interfaces near the other interfaces (after `HealthEntry`):

```tsx
interface AtsTypeOption {
    value: string;
    label: string;
    count: number;
}

interface AggregatorOption {
    value: string;
    label: string;
}

interface TriggerOptions {
    ats_types: AtsTypeOption[];
    aggregators: AggregatorOption[];
}
```

Add two fields to the `Props` interface:

```tsx
    health: HealthEntry[];
    triggerOptions: TriggerOptions;
    activeRunCount: number;
```

- [ ] **Step 3: Accept the new props and wire polling + trigger**

Change the component signature:

```tsx
export default function RunsIndex({ runs, filters, options, health }: Props) {
```

to:

```tsx
export default function RunsIndex({ runs, filters, options, health, triggerOptions, activeRunCount }: Props) {
```

Immediately after `const [draft, setDraft] = useState<FilterState>(filters);`, add:

```tsx
    const [selectedAggregator, setSelectedAggregator] = useState<string>(
        triggerOptions.aggregators[0]?.value ?? '',
    );

    const flash = (usePage().props.flash ?? null) as { success?: string | null; error?: string | null } | null;

    const { start, stop } = usePoll(
        2500,
        { only: ['runs', 'health', 'activeRunCount'], preserveScroll: true, preserveState: true },
        { autoStart: false },
    );

    // Poll while any scrape is in flight; stop as soon as the count hits zero.
    useEffect(() => {
        if (activeRunCount > 0) {
            start();
        } else {
            stop();
        }
    }, [activeRunCount, start, stop]);

    function trigger(scope: string) {
        router.post(
            '/admin/runs/trigger',
            { scope },
            {
                preserveScroll: true,
                preserveState: true,
                only: ['runs', 'health', 'activeRunCount', 'flash'],
                onSuccess: () => start(),
            },
        );
    }
```

- [ ] **Step 4: Render the flash banner and "Run scrapers" panel**

In the returned JSX, locate the `<header>...</header>` block. Immediately after the closing `</header>`, insert the flash banner and the panel (before the `{health.length > 0 && (` block):

```tsx
                {flash?.success && (
                    <div className="rounded-xl border border-emerald-500/40 bg-emerald-500/10 px-4 py-2 text-sm text-emerald-700 dark:text-emerald-300">
                        {flash.success}
                    </div>
                )}

                <Card className="rounded-2xl">
                    <CardHeader className="flex flex-row items-center justify-between">
                        <CardTitle className="text-sm">Run scrapers</CardTitle>
                        {activeRunCount > 0 && (
                            <span className="flex items-center gap-1.5 text-xs font-medium text-sky-600 dark:text-sky-400">
                                <span className="h-2 w-2 animate-pulse rounded-full bg-sky-500" />
                                {activeRunCount} running
                            </span>
                        )}
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div>
                            <Button type="button" size="sm" onClick={() => trigger('all')}>
                                Run everything
                            </Button>
                        </div>

                        <div className="flex flex-col gap-2">
                            <span className="text-xs font-medium">ATS scrapers</span>
                            <div className="flex flex-wrap gap-1.5">
                                <Button type="button" size="sm" variant="outline" onClick={() => trigger('ats')}>
                                    Run all ATS
                                </Button>
                                {triggerOptions.ats_types.map((t) => (
                                    <Button
                                        key={t.value}
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        onClick={() => trigger(`ats:${t.value}`)}
                                    >
                                        {t.label} ({t.count})
                                    </Button>
                                ))}
                            </div>
                        </div>

                        <div className="flex flex-col gap-2">
                            <span className="text-xs font-medium">Aggregators</span>
                            <div className="flex flex-wrap items-center gap-1.5">
                                <Button type="button" size="sm" variant="outline" onClick={() => trigger('aggregators')}>
                                    Run all aggregators
                                </Button>
                                {triggerOptions.aggregators.length > 0 && (
                                    <>
                                        <select
                                            className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                                            value={selectedAggregator}
                                            onChange={(e) => setSelectedAggregator(e.target.value)}
                                        >
                                            {triggerOptions.aggregators.map((a) => (
                                                <option key={a.value} value={a.value}>
                                                    {a.label}
                                                </option>
                                            ))}
                                        </select>
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="outline"
                                            disabled={!selectedAggregator}
                                            onClick={() => trigger(`aggregator:${selectedAggregator}`)}
                                        >
                                            Run
                                        </Button>
                                    </>
                                )}
                            </div>
                        </div>
                    </CardContent>
                </Card>
```

- [ ] **Step 5: Build to type-check the page**

Run: `pnpm run build`
(If the frontend toolchain runs inside the node container, use `spin run -T node pnpm run build`.)
Expected: build succeeds with no TypeScript errors in `Index.tsx`.

- [ ] **Step 6: Manual smoke test**

Ensure the dev stack and a queue worker (Horizon) are running. Open `/admin/runs`, click "Run all aggregators" (or a single aggregator), and confirm:
- A "Queued N scraper runs." banner appears.
- New rows show `running` and flip to a terminal status within a few seconds.
- The "● N running" indicator appears while runs are in flight and disappears when done.

- [ ] **Step 7: Commit**

```bash
git add resources/js/Pages/Admin/Runs/Index.tsx
git commit -m "feat: run-scrapers panel with live polling on /admin/runs"
```

---

## Task 6: Full verification

- [ ] **Step 1: Run the scraper/runs/jobs test slice**

Run: `spin exec -T php php artisan test --compact --filter="RunCompanyScrapeJobTest|RunAggregatorScrapeJobTest|TriggerTest|IndexTest"`
Expected: all PASS.

- [ ] **Step 2: Run the full suite (ask the user first per project convention)**

Run: `spin exec -T php php artisan test --compact --parallel --recreate-databases`
Expected: green. Investigate any failure before finalizing.

- [ ] **Step 3: Final format check**

Run: `spin run -T php ./vendor/bin/pint --dirty --format agent`
Expected: no changes (already formatted) or auto-fixes applied; commit if anything changes.

---

## Self-review notes (resolved during planning)

- **Spec coverage:** jobs (Task 1–2), trigger controller + scopes incl. per-ATS-type and per-aggregator (Task 3), `triggerOptions`/`activeRunCount` (Task 4), panel + polling + flash (Task 5), tenancy isolation test (Task 3), error/no-op handling (job tests + `ShouldBeUnique`), testing section (Task 1–4, 6). All spec sections map to a task.
- **Per-aggregator runs even when disabled** is intentional (matches the manual-intent of the Discovery "Run now"); "run all aggregators" stays enabled-only. Encoded in `dispatchOneAggregator` vs `dispatchAllAggregators` and covered by two tests.
- **Type consistency:** prop keys `triggerOptions.{ats_types,aggregators}` and `activeRunCount`, scope strings (`all`/`ats`/`ats:{type}`/`aggregators`/`aggregator:{type}`), and job constructor args `(tenantId, companyId)` / `(tenantId, sourceId)` are identical across controller, props, tests, and React.
- **Tenancy in tests (verified):** `UserFactory` reads the current tenant from `TenantContext`; tests set that context in `setUp()` before creating the user, so the acting user is attached to the tenant automatically. No extra wiring needed.
