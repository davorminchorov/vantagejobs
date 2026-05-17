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
┌────────────────────────────────────────────────────────────┐
│                         Hetzner VPS                         │
│                                                             │
│  ┌──────────────┐    ┌────────────────────────────────┐    │
│  │  FrankenPHP  │    │         cron (every min)        │    │
│  │  (classic    │    │  → php artisan schedule:run    │    │
│  │   mode +     │    └────────────────────────────────┘    │
│  │   Caddy TLS) │                                          │
│  │              │             ↓                            │
│  │  Inertia +   │    ┌────────────────────────────────┐    │
│  │  React       │    │       Scheduled commands        │    │
│  │  frontend    │    │  - scrape:all (every 6h)        │    │
│  │              │    │  - scrape:aggregators (daily)   │    │
│  └──────┬───────┘    │  - digest:send (daily 7am)      │    │
│         │            │  - signals:layoffs (weekly)     │    │
│         │            │  - signals:github (weekly)      │    │
│         │            │  - signals:blogs (daily)        │    │
│         │            │  - artifacts:purge (daily)      │    │
│         │            │  - backup:run (daily 2am)       │    │
│         │            └────────────────────────────────┘    │
│         │                          │                       │
│         └────────────┬─────────────┘                       │
│                      ↓                                     │
│             ┌──────────────────┐                           │
│             │  PostgreSQL 18   │                           │
│             │  (single instance)│                          │
│             └──────────────────┘                           │
│                      │                                     │
└──────────────────────┼─────────────────────────────────────┘
                       │
                       ↓
           ┌────────────────────────┐
           │   Backblaze B2 bucket   │
           │   (nightly pg_dump)     │
           └────────────────────────┘

           Outbound calls:
           ─→ Greenhouse / Lever / Ashby APIs
           ─→ HN Algolia / Remotive / Himalayas / WWR / RemoteOK
           ─→ layoffs.fyi / TrueUp / GitHub API / blog RSS feeds
           ─→ Postmark / Resend (outbound mail)
```

Single user, single instance, single language end-to-end via Inertia. No SPA, no microservices, no queue worker farm.

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

### Identity & Auth

- **User** — single row. Email, password (bcrypt), TOTP secret, recovery codes.
- **AuditLog** — append-only record of security-sensitive actions.
- **Setting** — runtime-tunable key-value config (DND window, polling cadences, etc.).

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

See `database/migrations/` for the authoritative schema. Migrations are append-only; never edit a shipped migration.

---

## Request flow (Inertia)

```
Browser request
  → FrankenPHP / Caddy
  → Laravel router
  → Auth middleware (Fortify session)
  → Controller method
    └─ Eloquent queries (read)
    └─ Server actions / Form Requests (write)
  → Return Inertia::render('Page/Name', $props)
  → Inertia middleware serializes props to JSON
  → React component receives typed props
  → Render
```

No separate API. No tokens. No CORS. Every page is a controller method that returns props. Shared props (auth user, flash messages, env-driven UI flags) live in `app/Http/Middleware/HandleInertiaRequests.php`.

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

- **Host:** Hetzner CX22 in Germany region (€5.18/mo).
- **OS:** Ubuntu 24.04 LTS.
- **PHP:** 8.5 via deb.sury.org repo.
- **Postgres:** 18 from PGDG repo, on the same VPS.
- **FrankenPHP:** Single binary at `/usr/local/bin/frankenphp`. Runs as systemd service.
- **Domain:** Behind a registrar with DNSSEC enabled. A record points to VPS.
- **TLS:** Auto-managed by Caddy (embedded in FrankenPHP). Let's Encrypt.

### Systemd unit

```ini
# /etc/systemd/system/frankenphp.service
[Unit]
Description=FrankenPHP
After=network.target postgresql.service

[Service]
ExecStart=/usr/local/bin/frankenphp run --config /etc/frankenphp/Caddyfile
WorkingDirectory=/var/www/jobs
User=deploy
Restart=on-failure
RestartSec=5

[Install]
WantedBy=multi-user.target
```

### Caddyfile

```caddy
# /etc/frankenphp/Caddyfile
jobs.yourdomain.tld {
    root * /var/www/jobs/public
    php_server
    encode zstd gzip
    
    header {
        Strict-Transport-Security "max-age=31536000; includeSubDomains"
        X-Frame-Options "DENY"
        Referrer-Policy "strict-origin-when-cross-origin"
        X-Content-Type-Options "nosniff"
        -Server
    }
}
```

### Cron

```cron
# /var/spool/cron/crontabs/deploy
* * * * * cd /var/www/jobs && php artisan schedule:run >> /var/log/jobs-schedule.log 2>&1
```

Laravel's scheduler in `app/Console/Kernel.php` then takes care of dispatching the actual artisan commands at their declared cadences. See PRD section 8.7 for the per-task schedule.

### Deploys

Via Laravel Forge (recommended) or via a tiny Ansible playbook. The deploy script:

1. `git pull` on `main`
2. `composer install --no-dev --optimize-autoloader`
3. `npm ci && npm run build`
4. `php artisan migrate --force`
5. `php artisan config:cache && php artisan route:cache && php artisan view:cache`
6. `systemctl reload frankenphp` (graceful reload)

Rollback procedure: see [`RUNBOOK.md#rollback`](RUNBOOK.md#rollback).

### Backups

Nightly `pg_dump` to Backblaze B2 via `php artisan backup:run`. Retention tiering: 30 days daily / 6 months weekly / monthly indefinite. Monthly restore-to-scratch-DB job verifies backups aren't corrupt.

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
- Year-2 DB size estimate: 8-12 GB. Still fits on the $5 VPS comfortably.

If growth exceeds expectations, mitigations are in [`RUNBOOK.md#database-growth-tracking`](RUNBOOK.md#database-growth-tracking).

---

## What's intentionally NOT here

A few things that someone evaluating the architecture might expect to see — and don't, on purpose:

- **No Redis.** Single user, no need for a shared cache. Use Laravel's file-driver cache; it's fine.
- **No Horizon.** No queue workers in v1. Scheduled commands run synchronously and exit. Add Horizon in Phase 6+ only if throughput demands it.
- **No Docker in production.** FrankenPHP single-binary deployment is simpler than maintaining a container.
- **No CI/CD pipeline for production deploys** in v1. Forge or a manual deploy script is fine for one-person, low-traffic. Add GitHub Actions for tests on PRs only.
- **No separate API layer.** Inertia eliminates the need.
- **No microservices.** One Laravel app, one DB, one process model.
- **No LLM integration anywhere.** Not in parsing, not in summarization, not in matching. Determinism > convenience. See PRD section 12.

---

**Last updated:** May 2026. Update this doc whenever a major architectural decision changes.
