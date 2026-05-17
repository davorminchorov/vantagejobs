# Architecture

**Purpose:** High-level system overview, data flow, key models, key commands. Read this when you need to understand *how* the platform works, not *what* it does.

**Audience:** Future-you returning to the codebase after months away, or someone you've decided to show the project to.

**Companion docs:**
- [`PRD.md`](PRD.md) — what and why
- [`SOURCES.md`](SOURCES.md) — per-source integration details
- [`RUNBOOK.md`](RUNBOOK.md) — operational playbook

---

## System overview

```
┌──────────────────────────────────────────────────────────────┐
│             Laravel Cloud  ·  EU region (Frankfurt)           │
│                                                               │
│  ┌──────────────┐     ┌──────────────────────────────────┐    │
│  │  FrankenPHP  │     │   Cloud scheduled task (every min)│   │
│  │  (classic    │     │   → php artisan schedule:run     │    │
│  │   mode,      │     └──────────────────────────────────┘    │
│  │   Cloud-     │                                             │
│  │   managed,   │              ↓                              │
│  │   TLS at     │     ┌──────────────────────────────────┐    │
│  │   edge)      │     │       Scheduled commands          │   │
│  │              │     │  - scrape:all (every 6h)          │   │
│  │  Inertia +   │     │  - scrape:aggregators (daily)     │   │
│  │  React       │     │  - digest:send (daily 7am)        │   │
│  │  frontend    │     │  - signals:layoffs (weekly)       │   │
│  │              │     │  - signals:github (weekly)        │   │
│  └──────┬───────┘     │  - signals:blogs (daily)          │   │
│         │             │  - artifacts:purge (daily)        │   │
│         │             │  - backup:run (daily 2am UTC)     │   │
│         │             └──────────────────────────────────┘    │
│         │                          │                          │
│         └────────────┬─────────────┘                          │
│                      ↓                                        │
│           ┌────────────────────────────┐                      │
│           │  Cloud-managed PostgreSQL 18│                     │
│           │  (single instance, PITR on) │                     │
│           └────────────────────────────┘                      │
│                      │                                        │
└──────────────────────┼────────────────────────────────────────┘
                       │
                       ↓
           ┌────────────────────────────────────┐
           │   Backblaze B2 bucket               │
           │   (off-platform nightly pg_dump)    │
           └────────────────────────────────────┘

           Outbound calls (from Cloud compute):
           → Greenhouse / Lever / Ashby APIs
           → HN Algolia / Remotive / Himalayas / WWR / RemoteOK
           → layoffs.fyi / TrueUp / GitHub API / blog RSS feeds
           → Postmark / Resend (outbound mail)
```

Single active tenant (just me), single instance, single language end-to-end via Inertia. No SPA, no microservices, no queue worker farm. Multi-tenancy is *schema-level* from day one (every domain row has a `tenant_id`); operationally there is one tenant in v1 and no UI surface to switch.

Local dev mirrors the topology via Spin (Docker Compose) wrapping `serversideup/php` images: a `php` service running FrankenPHP, a `node` service for Vite, and a `postgres` service for the database. Local domain is `vantagejobs.test`. Every `php`, `artisan`, `composer`, `vendor/bin/*`, and `npm` command runs through `spin run php` / `spin run node` (or `spin exec` when the stack is up).

---

## Core data flow

### Scraping → DB

```
cron (every 6h)
  → php artisan scrape:all
    → for each enabled Source:
      → for each tracked Company (or AggregatorSource):
        → Scraper.run()
          ├─→ Guzzle Pool (concurrent fetches, per-source concurrency cap)
          ├─→ HTTP response stored as ScrapeRunArtifact (7-day retention)
          ├─→ Parse to Role[] or AggregatorListing[]
          ├─→ Diff vs existing DB rows
          │   ├─ New row → INSERT + Event(type=new_role)
          │   ├─ Missing row → UPDATE is_open=false + Event(type=role_closed)
          │   └─ Existing row → UPDATE last_seen_at
          └─→ UPDATE company.last_checked_at
        → log ScrapeRun row (start, end, fetched, new, errors)
```

### Discovery → Triage → Watch list

```
Aggregator scrapers populate AggregatorListing(status='new')
  ↓
Parser runs over each listing (heuristic extractors, confidence scores)
  ↓
Auto-link check: if parsed company name matches existing Company (fuzzy ≥0.85):
  → set matched_company_id, status='linked', create Event on matched Company
Else:
  → stay as status='new' in /discover queue
  ↓
User reviews in /discover:
  ├─ Keep → create new Company or link to existing → status='kept'
  ├─ Dismiss → status='dismissed', counter increments DismissedCompany
  └─ Snooze → status='new' with snoozed_until=+7d
  ↓
After 3 dismissals: DismissedCompany.auto_dismiss=true
  → future listings from that employer arrive as status='auto_dismissed'
```

### Signals → Quality score

```
Daily/weekly signal jobs:
  ├─ layoffs.fyi scrape → match → Event(type=layoff)
  ├─ GitHub org poll → eng_activity_score on Company
  ├─ Blog RSS poll → Event(type=blog_post) + cadence metric
  ├─ Google News (per Company) → Event(type=funding) candidates
  └─ Daily CompanySnapshot row written (open_role_count, tier, status)
        ↓
QualityScoreCalculator runs nightly:
  ├─ Positive contributions: funding_recency, eng_activity, blog_cadence,
  │   hiring_volume_stability, comp_transparency
  ├─ Negative contributions: layoffs, hiring_volume_decline, ghost_job_density,
  │   no_blog, no_oss, red_flag_terms
  └─ Stored on Company.quality_score (1-100) with breakdown JSON
```

---

## Key models

The schema is designed around five domain pillars. Detail in `database/migrations/`.

### Identity, Tenancy & Auth

- **Tenant** — the data-ownership boundary. Every domain row has `tenant_id` referencing this table. One row in v1 (mine). Has `name`, `slug`, `timezone`, `created_at`. No deletion; deactivation only.
- **User** — belongs to one Tenant via `tenant_id`. Email, password (bcrypt), TOTP secret, recovery codes, per-user timezone (defaults to the Tenant's). Exactly one user row in v1.
- **AuditLog** — append-only record of security-sensitive actions. Always carries `tenant_id` (or NULL for unauthenticated events like failed logins where no Tenant is yet resolved).
- **Setting** — runtime-tunable key-value config (DND window, polling cadences, etc.). Tenant-scoped: every Setting row carries `tenant_id`.

### Tracked entities

- **Company** — the canonical employer. ATS type and slug, tier (A/B/C/graveyard), status, quality score, funding info, GitHub org, blog RSS URL, last_checked_at.
- **Role** — a specific job posting at a Company. External ID + source = uniqueness. is_open, applied, applied_at, source (cold/referral/recruiter), pipeline status.
- **Tag** — free-form labels on Companies and Roles. Polymorphic many-to-many.
- **Contact** — light CRM per Company (name, role, linkedin, email, last_touch).
- **Dossier** — long-form markdown attached to A-tier Companies.

### Events & history

- **Event** — timestamped fact about a Company (new_role, role_closed, funding, layoff, blog_post, hire, departure, restored, my_note).
- **Note** — free-text notes attached to a Company. Distinct from Events; Notes are mine, Events are observed.
- **CompanySnapshot** — daily roll-up per Company (open_role_count, tier, status). Substrate for trend charts.
- **CalendarEvent** — datetime-anchored items on Role/Company/Contact (interview, deadline, reminder). Polymorphic.

### Discovery layer

- **AggregatorSource** — registry row per discovery source (HN, Remotive, etc.).
- **AggregatorListing** — raw freeform job postings before triage. 90-day retention.
- **ParseCorrection** — append-only log of user-corrected parses. Feeds the test fixtures and protects against re-parse overwrites.
- **DismissedCompany** — auto-dismiss memory (normalized name + dismiss count).

### Operations

- **ScrapeRun** — one row per scheduled run. Per-source summary stats.
- **ScrapeRunArtifact** — raw HTTP response blob, 7-day retention. For debugging.
- **Bookmark** — "watch later" flag on Roles. 30-day auto-expiry.
- **Template** — message templates for outreach (categorized).

**Tenancy invariant:** every table listed above (except `Tenant` itself) carries a `tenant_id` foreign key. A `BelongsToTenant` trait on every Eloquent model registers a global scope that filters on `TenantContext::current()->id`, and a model observer auto-fills `tenant_id` on insert. Tests that need to bypass scoping use `Model::withoutGlobalScope(BelongsToTenantScope::class)` explicitly and never silently. New migrations and new models MUST include `tenant_id`; it is a code review tripwire.

See `database/migrations/` for the authoritative schema. Migrations are append-only; never edit a shipped migration.

---

## Request flow (Inertia)

```
Browser request
  → Laravel Cloud edge (TLS termination)
  → FrankenPHP (classic mode, Cloud-managed)
  → Laravel router
  → Auth middleware (Fortify session)
  → ResolveTenantContext middleware (sets TenantContext from auth()->user()->tenant_id)
  → Controller method
    └─ Eloquent queries (read)   ← BelongsToTenant scope filters tenant_id automatically
    └─ Server actions / Form Requests (write)   ← model observer auto-fills tenant_id
  → Return Inertia::render('Page/Name', $props)
  → Inertia middleware serializes props to JSON
  → React component receives typed props
  → Render
```

No separate API. No tokens. No CORS. Every page is a controller method that returns props. Shared props (auth user, current tenant slug/timezone, flash messages, env-driven UI flags) live in `app/Http/Middleware/HandleInertiaRequests.php`.

Artisan commands and scheduled tasks resolve the Tenant explicitly. A command that operates over tenant data must either:

1. Accept a `--tenant=` option and call `app(TenantContext::class)->setCurrent(Tenant::whereSlug(...)->first())` at the top, **or**
2. Loop over all active tenants and call `setCurrent()` once per iteration.

Forgetting to set tenant context inside a command is the kind of bug that returns zero rows everywhere and silently writes orphaned data. The `BelongsToTenant` observer throws if it tries to insert without a tenant resolved, which is the intended tripwire.

---

## Scraper architecture

Per [`SOURCES.md`](SOURCES.md) Appendix A, every scraper implements `ScraperInterface`:

```php
interface ScraperInterface
{
    public function name(): string;
    public function run(mixed $target, Client $http): ScraperRunResult;
    public function concurrency(): int;
    public function schedule(): string;
}
```

Two specializations:

- **`AtsScraperInterface`** — operates per Company. Returns Role rows. Examples: GreenhouseScraper, LeverScraper, AshbyScraper.
- **`AggregatorScraperInterface`** — operates per AggregatorSource. Returns AggregatorListing rows. Examples: HnHiringScraper, RemotiveScraper, HimalayasScraper.

**Orchestration:**

```php
// app/Console/Commands/ScrapeAllCommand.php
foreach ($enabledSources as $source) {
    $scraper = app($source->scraper_class);
    $targets = $source->getTargets(); // Companies for ATS, just self for aggregator
    
    $pool = new Pool($http, $this->buildRequests($scraper, $targets), [
        'concurrency' => $scraper->concurrency(),
        'fulfilled' => fn($response, $target) => $scraper->handle($response, $target),
        'rejected' => fn($reason, $target) => $this->logError($reason, $target),
    ]);
    
    $pool->promise()->wait();
}
```

**Why this shape:**
- Adding a new source is a one-class change (plus a registry entry).
- Per-source concurrency caps prevent hammering any single host.
- Error isolation: one failing company doesn't break others on the same source.
- Idempotency is enforced at the diff level, not the scrape level — re-running is always safe.

---

## Parser pipeline

Aggregator listings need parsing because their input is freeform text. Parser lives at `app/Parsers/`.

```
RawListing
  ↓
SourceAdapter (per-source pre-processing — HN/Remotive/WWR/etc.)
  ↓
Pipeline runs each Extractor:
  ├─ CompanyExtractor      → ParsedField<string>
  ├─ RolesExtractor        → ParsedField<RoleInfo[]>
  ├─ LocationExtractor     → ParsedField<LocationInfo>
  ├─ RemoteExtractor       → ParsedField<RemoteInfo>
  ├─ CompensationExtractor → ParsedField<CompInfo|null>
  ├─ StackExtractor        → ParsedField<string[]>
  ├─ SeniorityExtractor    → ParsedField<SeniorityLevel|null>
  ├─ EmploymentTypeExtractor
  ├─ VisaSponsorshipExtractor
  └─ ApplyMethodExtractor
  ↓
Merger combines results, weights confidence
  ↓
Normalizer cleans (currency normalization, name suffix stripping, role title canonicalization)
  ↓
Validator clamps impossible values
  ↓
ParsedListing { ...fields, overallConfidence, parserVersion }
```

**Confidence is honest.** Each ParsedField carries:
- `value`
- `confidence` (0.0 to 1.0)
- `source` (`explicit` | `inferred` | `default` | `missing`)
- `rawMatch` (the text snippet that produced the value, for debugging)

When the extractor is guessing, confidence is low. The UI uses confidence to decide what to surface for review (Phase 3).

**Corrections preserved.** When the user corrects a parse via the UI, a `ParseCorrection` row is written. Re-parses preserve corrected fields unless `--force-corrections` is passed.

**Tests are fixture-driven.** Every parser bug becomes a fixture in `tests/fixtures/parser/`. The fixture suite is the regression net.

**No LLMs.** All extractors are pure functions over normalized input. Deterministic, testable, free. Per PRD section 12.

---

## Frontend conventions

- **One Inertia page per route.** Pages live in `resources/js/Pages/{Area}/{Page}.tsx`.
- **Layouts** in `resources/js/Layouts/`. Default is `AuthenticatedLayout`. The `/login` and `/up` routes use `GuestLayout`.
- **shadcn/ui components** in `resources/js/components/ui/`. Installed via shadcn CLI, not as a package. Customize freely.
- **Domain components** in `resources/js/components/{domain}/`. E.g., `components/triage/ListingCard.tsx`.
- **Hooks** in `resources/js/hooks/`. E.g., `useDebouncedSearch`, `useShortcuts`.
- **Types** in `resources/js/types/`. Mirror the PHP model props returned by Inertia.
- **TanStack Query** only for client-side fetching beyond Inertia page loads (scrape status polling, parser stats).
- **No Redux, no Zustand, no other state library** unless we hit a real need.

Form pattern: Inertia's `useForm` hook + Laravel Form Request for server-side validation. Errors flow back as Inertia shared props automatically.

---

## Deployment

### Production environment

- **Host:** Laravel Cloud, EU region (Frankfurt or equivalent for GDPR).
- **Runtime:** FrankenPHP (classic mode) managed by Cloud. PHP 8.5.
- **Database:** Cloud-managed PostgreSQL 18. Single instance, point-in-time recovery enabled.
- **Edge:** TLS termination, HTTPS, HSTS, and certificate renewal handled by Cloud's edge. We declare security headers in the Laravel app (`SecurityHeaders` middleware), Cloud passes them through.
- **Domain:** Custom domain configured via the `cloud` CLI. DNSSEC enabled at the registrar.
- **Environment:** Single `production` environment in v1. A `staging` environment can be added later if a feature ever needs preview-before-prod.

Cloud configuration lives in the repo at `cloud.yaml` (or whatever the current Cloud manifest filename is). Secrets live in Cloud's encrypted store, not in the repo, managed via `cloud env:set`. Read the [`deploying-laravel-cloud`](https://docs.cloud.laravel.com) docs / skill before touching infra config.

### Local development

Spin wraps Docker Compose for local dev:

- `spin up` — start the stack (FrankenPHP + Node + Postgres) at `https://vantagejobs.test`.
- `spin exec -T php php artisan ...` — run artisan in the running stack.
- `spin run -T php composer ...` — run composer in a one-off container when the stack is down.
- `spin run -T php ./vendor/bin/pint --dirty --format agent` — format dirty PHP.
- `spin exec -T php php artisan test --compact --parallel --recreate-databases` — run the test suite.

`docker-compose.dev.yml` is the source of truth for local services. `docker-compose.ci.yml` mirrors it for CI parity. `docker-compose.prod.yml` exists for completeness but is not the production deployment path; Laravel Cloud is.

### Scheduler

Laravel's scheduler runs as a Cloud scheduled task firing every minute, equivalent to:

```
* * * * * php artisan schedule:run
```

No host cron to manage; Cloud's scheduler invokes the command. The schedule definitions live in `app/Console/Kernel.php` (or `routes/console.php` in Laravel 13's preferred shape), declaring each scrape, signal, digest, and purge job with its cadence. See PRD section 8.7 for the per-task schedule.

### Deploys

`git push` to `main` triggers Cloud's build pipeline:

1. `composer install --no-dev --optimize-autoloader`
2. `npm ci && npm run build`
3. `php artisan migrate --force`
4. `php artisan config:cache && php artisan route:cache && php artisan view:cache`
5. Cloud cuts over to the new release; old release stays available for instant rollback.

Manual operations use the `cloud` CLI:

```bash
cloud deploy           # trigger a deploy
cloud rollback         # roll back to the previous release
cloud env:set KEY=val  # set a production env var (encrypted)
cloud logs --tail      # tail production logs
cloud shell            # open a shell in the production container (read-only by default)
```

Rollback procedure: see [`RUNBOOK.md#rollback`](RUNBOOK.md#rollback).

### Backups

Two layers:

1. **Primary:** Laravel Cloud managed Postgres backups. Point-in-time recovery enabled. Nightly snapshots retained per Cloud's plan defaults. Restore is a Cloud-side operation.
2. **Off-platform redundancy:** A scheduled `php artisan backup:run` exports `pg_dump` nightly to Backblaze B2. Retention tiering: 30 days daily / 6 months weekly / monthly indefinite. Used for disaster scenarios where Cloud itself is the problem.

A monthly restore-to-scratch-DB job verifies that the B2 backups are valid (not just present).

See [`RUNBOOK.md#backup-restore`](RUNBOOK.md#backup-restore) for restore procedures.

---

## Key directory layout

```
.
├── app/
│   ├── Console/
│   │   ├── Commands/             # Artisan commands (scrape, digest, etc.)
│   │   └── Kernel.php             # Schedule declarations
│   ├── Http/
│   │   ├── Controllers/           # Inertia page controllers
│   │   ├── Middleware/            # Auth, Inertia shared props
│   │   └── Requests/              # Form Requests for validation
│   ├── Models/                    # Eloquent models
│   ├── Parsers/
│   │   ├── Extractors/            # Per-field extractors (Company, Role, etc.)
│   │   ├── Normalizers/           # Post-extraction cleaning
│   │   ├── Dictionaries/          # Stack tags, role patterns, locations
│   │   ├── SourceAdapters/        # Per-source pre-processing
│   │   └── Pipeline.php           # Orchestrates extractors
│   ├── Scrapers/
│   │   ├── Contracts/             # ScraperInterface, ATS/Aggregator subtypes
│   │   ├── Sources/               # One class per source
│   │   └── ScraperRegistry.php
│   ├── Services/                  # Domain services (QualityScoreCalculator, etc.)
│   ├── Tenancy/
│   │   ├── Tenant.php             # Eloquent model
│   │   ├── TenantContext.php      # Resolves current tenant per request/command
│   │   ├── BelongsToTenant.php    # Trait: global scope + observer for auto-fill
│   │   ├── BelongsToTenantScope.php
│   │   └── ResolveTenantContext.php # Middleware
│   └── Support/                   # Helpers, value objects
├── bootstrap/
├── config/
├── database/
│   ├── migrations/
│   ├── seeders/
│   └── factories/
├── docs/
│   ├── PRD.md
│   ├── SOURCES.md
│   ├── ARCHITECTURE.md
│   ├── RUNBOOK.md
│   ├── PHASE_0_NOTES.md
│   ├── PHASE_1_NOTES.md
│   └── ...
├── resources/
│   ├── css/                       # Tailwind base + custom CSS
│   └── js/
│       ├── Pages/                 # One file per Inertia page
│       ├── Layouts/
│       ├── components/
│       │   ├── ui/                # shadcn primitives
│       │   ├── triage/
│       │   ├── pipeline/
│       │   └── ...
│       ├── hooks/
│       ├── lib/                   # Utility functions
│       └── types/                 # TS type definitions
├── routes/
│   ├── web.php                    # Authenticated routes
│   └── auth.php                   # Login, password reset, 2FA
├── storage/
│   ├── app/
│   │   └── scrape-artifacts/      # 7-day raw HTTP response retention
│   └── logs/
└── tests/
    ├── Feature/                   # End-to-end tests
    ├── Unit/                      # Pure function tests
    └── fixtures/
        ├── parser/                # Real-world listings with expected ParsedListing output
        └── scrapers/              # Recorded HTTP responses for scraper tests
```

---

## Performance and capacity

Per PRD section 8.8 and 8.9:

- Full scrape run (~500 companies, 8 sources): under 90 seconds.
- Dashboard p95 load: under 200ms.
- Year-1 DB size: ~3-5 GB.
- Year-2 DB size estimate: 8-12 GB. Comfortably within Laravel Cloud Postgres's small/medium tiers; revisit tier sizing as growth gets close to the limit.

If growth exceeds expectations, mitigations are in [`RUNBOOK.md#database-growth-tracking`](RUNBOOK.md#database-growth-tracking).

---

## What's intentionally NOT here

A few things that someone evaluating the architecture might expect to see — and don't, on purpose:

- **No Redis (yet).** Single tenant, no need for a shared cache. Use Laravel's file/database cache driver locally and Cloud's default cache driver in prod. Horizon is installed in the scaffold and ready to enable if a real queue workload ever appears; for now no queue workers run.
- **No always-on workers.** Scheduled commands run synchronously inside the Cloud scheduler invocation and exit. Octane is installed but FrankenPHP worker mode is disabled in v1.
- **No self-managed VPS.** Earlier drafts of the PRD targeted a Hetzner VPS via Forge/Ansible. v3 of the PRD reversed that decision in favor of Laravel Cloud; see PRD section 9 for the rationale.
- **No production deploy CI workflow we maintain.** Laravel Cloud's built-in pipeline handles build + deploy on `git push`. We do run GitHub Actions for tests and Pint on PRs.
- **No separate API layer.** Inertia eliminates the need.
- **No microservices.** One Laravel app, one DB, one process model.
- **No LLM integration anywhere.** Not in parsing, not in summarization, not in matching. Determinism > convenience. See PRD section 12.
- **No user-facing multi-tenancy in v1.** The schema is tenant-aware so that future expansion is cheap, but v1 ships one Tenant, one User, and no tenant switcher anywhere in the UI.
- **No third-party tenancy package.** `stancl/tenancy` and `spatie/laravel-multitenancy` are both fine; for a hand-rolled `tenant_id` + global scope at this scale, neither earns its keep. Revisit if/when v2 wants subdomain routing or per-tenant database isolation.

---

**Last updated:** May 2026. Update this doc whenever a major architectural decision changes.
