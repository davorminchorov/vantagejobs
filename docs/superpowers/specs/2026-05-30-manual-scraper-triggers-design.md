# Manual scraper triggers

**Date:** 2026-05-30
**Status:** Approved (design)

## Problem

Scrapers run only on the scheduler (ATS via cron-invoked `scrape:all`, aggregators
daily/monthly via `aggregator:run` in `routes/console.php`). There is no way to
kick a scrape off on demand from the UI, except the synchronous per-aggregator
"Run now" button on the Discovery Sources page. The user wants to trigger scrapers
manually whenever they choose, with per-source granularity and a "run all" option,
and see progress without waiting for the scheduler.

## Decisions (from brainstorming)

- **Coverage:** per-source triggers + a global "run all".
- **Execution:** queued (Redis + Horizon), not synchronous.
- **Placement:** the Admin Runs page (`/admin/runs`).
- **ATS granularity:** per ATS *type* (Greenhouse, Lever, ...), plus run-all.
- **Aggregator granularity:** per individual aggregator source on the Runs panel too,
  in addition to the global run-all.
- **Feedback:** live polling of the runs table until in-flight runs finish.
- **Auth:** no new admin role. Reuse the existing `auth`-only middleware that already
  guards every `/admin/*` route.

## Architecture

Two thin queued jobs wrap the existing orchestrators (which already open, run, and
finalize a `scrape_runs` row). One trigger controller fans jobs out onto Horizon by
scope. The Admin Runs page gains a "Run scrapers" panel and Inertia polling.

The existing orchestrators (`App\Scrapers\ScrapeOrchestrator::runForCompany()` and
`App\Scrapers\Aggregator\AggregatorOrchestrator::runForSource()`) are reused
**unchanged**. Each already creates a `scrape_runs` row with `status='running'` and
finalizes it to `completed` / `not_modified` / `partial` / `error`. That `running`
row is exactly what live polling watches.

## Tenancy constraint

Tenant is resolved from `user.tenant_id` by the `ResolveTenantContext` middleware and
held in the `TenantContext` singleton. Models use the `BelongsToTenant` global scope,
so a queued job **must** call `$tenantContext->setCurrent($tenant)` at the top of
`handle()` before touching the DB, or the `scrape_runs` rows won't be stamped/scoped
correctly. Queue workers are long-lived, so each job sets context explicitly rather
than relying on leftover state from a previous job.

## Components

### 1. Queued jobs (`app/Jobs/` — first jobs in this app)

`RunCompanyScrapeJob`
- Constructor: `(int $tenantId, int $companyId)`.
- `handle(TenantContext $context, ScrapeOrchestrator $orchestrator)`: load `Tenant` by
  id; if missing, return (log + no-op). `$context->setCurrent($tenant)`. Load `Company`
  by id (now tenant-scoped); if missing, return. Call `$orchestrator->runForCompany($company)`.

`RunAggregatorScrapeJob`
- Constructor: `(int $tenantId, int $sourceId)`.
- `handle(TenantContext $context, AggregatorOrchestrator $orchestrator)`: same tenant
  setup, load `AggregatorSource` by id, call `$orchestrator->runForSource($source)`.

Both jobs:
- `implements ShouldQueue, ShouldBeUnique`.
- `uniqueId()` = class short name + `tenantId` + target id. `uniqueFor` ~ 1800s, so a
  second click while a target is mid-run is a no-op rather than a duplicate run.
- `public int $tries = 1;` — orchestrators already capture scraper failures into the
  run row; retries would only inflate `scrape_runs` with duplicate error rows.
- Default `default` queue → Horizon `supervisor-1` (per `config/horizon.php`).
- `final` classes, `declare(strict_types=1)`, constructor property promotion.

### 2. Trigger controller + request

Route (added inside the existing `admin.` group in `routes/web.php`):
```
Route::post('/runs/trigger', TriggerController::class)->name('runs.trigger');
```
Class: `App\Http\Controllers\Admin\Runs\TriggerController` (`final readonly`,
single `__invoke`).

`App\Http\Controllers\Admin\Runs\TriggerScrapersRequest` (array-based rules) validates
a single `scope` string against these shapes:
- `all` — every ATS-configured company + every enabled aggregator source.
- `ats` — all companies with `ats_type` and `ats_slug` set.
- `ats:{type}` — companies of one `AtsType` (`{type}` must be a valid `AtsType` value).
- `aggregators` — all enabled aggregator sources.
- `aggregator:{type}` — one source by `AggregatorSourceType` value.

Controller:
- Resolves the current tenant via `TenantContext::currentOrFail()`.
- Queries the targets (queries are tenant-scoped via the global scope, so cross-tenant
  leakage is impossible).
- Dispatches one job per target (`RunCompanyScrapeJob::dispatch($tenantId, $companyId)`
  / `RunAggregatorScrapeJob::dispatch($tenantId, $sourceId)`).
- Returns `back()->with('flash', ['level' => 'info', 'message' => "Queued {$n} scraper runs."])`.
- If a scope matches zero targets, flash a neutral `['level' => 'info', 'message' => 'Nothing to run for that selection.']`.

### 3. Admin Runs page (`Admin\Runs\IndexController` + `resources/js/Pages/Admin/Runs/Index.tsx`)

New props from `IndexController`:
- `triggerOptions`:
  - `ats_types`: list of `{ value: string, label: string, count: int }` for each
    `AtsType` that has at least one configured company (count = companies of that type).
    Types with zero companies are omitted.
  - `aggregators`: list of `{ value: string, label: string }` for each aggregator
    source (value = type string, label = source name).
- `activeRunCount`: count of `scrape_runs` rows with `status='running'` and
  `started_at >= now()->subHour()` for the current tenant.

New "Run scrapers" card rendered above the Filters card:
- **Run everything** button → `scope: 'all'`.
- **ATS** row: "Run all ATS" (`scope: 'ats'`) + one chip per `ats_types` entry labelled
  e.g. "Greenhouse (4)" → `scope: 'ats:greenhouse'`.
- **Aggregators** row: "Run all aggregators" (`scope: 'aggregators'`) + a compact
  `<select>` of `aggregators` + a "Run" button → `scope: 'aggregator:{type}'`
  (avoids ~22 loose buttons while keeping per-source control).
- A small live indicator: "● {activeRunCount} running" when `activeRunCount > 0`.

Each trigger fires:
```
router.post('/admin/runs/trigger', { scope }, {
  preserveScroll: true,
  preserveState: true,
  only: ['runs', 'health', 'activeRunCount', 'flash'],
  onSuccess: () => start(),
})
```

### 4. Live polling

```
const { start, stop } = usePoll(
  2500,
  () => ({ only: ['runs', 'health', 'activeRunCount'], preserveScroll: true, preserveState: true }),
  { autoStart: false },
)
```
- Started on any trigger (`onSuccess`).
- `usePoll`'s second arg is a function so each tick reflects the latest filter state;
  the partial reload of `runs` re-runs `IndexController` with the current query string,
  so polling respects active filters.
- A `useEffect` watches `activeRunCount`: when it reaches `0`, call `stop()`. On mount,
  if `activeRunCount > 0`, call `start()` (so reloading the page mid-run keeps updating).

## Data flow

1. User clicks a trigger button.
2. `POST /admin/runs/trigger` with `{ scope }`.
3. Controller resolves tenant, queries targets, dispatches N jobs to Horizon.
4. Each job sets tenant context and calls its orchestrator, which opens a `running`
   `scrape_runs` row and finalizes it.
5. `usePoll` partial-reloads `runs` / `health` / `activeRunCount` every 2.5s.
6. Rows flip `running` → terminal status in the table; `activeRunCount` decreases.
7. `activeRunCount` reaches 0 → polling stops.

## Error handling

- Scraper failures are already converted to `error` / `partial` rows by the
  orchestrators; jobs add no extra try/catch around the orchestrator call.
- A job that throws before the orchestrator runs (tenant or target deleted between
  dispatch and execution) returns early (no-op) for the missing-record case; any other
  throw fails on the queue and is logged. `tries = 1` prevents retry storms.
- `ShouldBeUnique` prevents duplicate concurrent runs of the same target.

## Testing (PHPUnit feature tests, `RefreshDatabase`)

Jobs:
- `RunCompanyScrapeJob` dispatched in tenant context creates a finalized `scrape_runs`
  row for the company (HTTP faked so the orchestrator runs without network).
- `RunAggregatorScrapeJob` likewise for a source.
- Missing company/source id is a safe no-op (no exception, no row).

`TriggerController` (`Queue::fake()`):
- `scope=all` dispatches one job per ATS company + one per enabled aggregator source.
- `scope=ats` dispatches only company jobs.
- `scope=ats:greenhouse` dispatches only Greenhouse companies.
- `scope=aggregators` dispatches only aggregator jobs (enabled only).
- `scope=aggregator:remotive` dispatches exactly one job for that source.
- Zero-target scope dispatches nothing and flashes the neutral message.
- Invalid `scope` fails validation (422 / redirect with errors).
- Guest is redirected (auth required).
- Tenant isolation: only the current tenant's companies/sources are dispatched.

`IndexController`:
- Response includes `triggerOptions` (ats_types with counts, aggregators) and
  `activeRunCount` with the expected shape/values.

## Out of scope

- No new admin role; existing `auth`-only gating is kept.
- The Discovery Sources page's existing synchronous per-aggregator "Run now" buttons
  stay as-is (not converted to queued in this change).
- No websockets / real-time transport beyond Inertia polling.
