# Phase 1 Notes

**Date:** May 2026
**Ship criterion (PRD §10):** "I can import my spreadsheet, add new companies, watch them get scraped, see new roles appear, and find anything with Cmd-K."
**Status:** Ship criterion met end-to-end on `main`. Locally runnable via `php artisan scrape:all --tenant=primary`. Production deploy still deferred from Wave F.

---

## What shipped

- **Step 1 — Schema.** Three string-backed enums (`AtsType`, `EventType`, `WorkplaceType`). Extended `roles` with `workplace_type`, `department`, `team`, `comp_min/max/currency/interval`, `raw` jsonb, plus widened `url` to TEXT. Extended `companies` with `etag`, `last_modified` for conditional GET. New tables: `scrape_runs` (per-run bookkeeping), `tags` + polymorphic `taggables` pivot. Postgres-only generated `tsvector` columns + GIN indexes on `companies.search` and `roles.search`. Morph map enforced (`'company'`, `'role'`) in `AppServiceProvider` — no FQCNs in the DB.
- **Step 3 — Scraper contract + registry.** `AtsScraperInterface` (`name`, `concurrency`, `run`). `ScraperRunResult` (`final readonly`) with a `notModified()` factory for 304/ETag shortcuts. `ScraperRegistry` scoped binding. `ScraperServiceProvider` merges `config/scrapers.php` (per-source concurrency, timeout, connect_timeout, retry policy, User-Agent).
- **Steps 4-6 — Greenhouse, Lever, Ashby scrapers.** Three Saloon connectors + per-endpoint Request classes + `*Scraper` implementations. Fan-out built in parallel git worktrees; each agent merged back independently. Fixture-based tests (`tests/Fixtures/scrapers/{source}/*.json`). Per-source quirks handled: Greenhouse's free-form `location.name` heuristic, Lever's `workplaceType` mapping (`on-site` → `ON_SITE`) and millisecond `createdAt`, Ashby's `CapitalCase` workplace types and highest-tier comp selection.
- **Step 7 — `RoleDiffer`.** Pure `plan()` + transactional `apply()` separation. Source-scoped diff (Phase 2 will add aggregator listings without breaking diff). Four outcomes: insert (`NEW_ROLE`), reopen (`ROLE_REOPENED`), close (`ROLE_CLOSED`), touch (silent `last_seen_at` refresh). Idempotency tested explicitly.
- **Step 8 — Orchestrator commands.** `scrape:company {id} --tenant=` and `scrape:all --tenant= --source=`. JSON output suitable for cron logs. Per-company error isolation, `scrape_runs` row per attempt. Status taxonomy: `running | completed | partial | not_modified | error`.
- **Step 9 — Companies index.** Inertia page with URL-state filters (tier, status, ats_type, last_checked range, has_open_roles, free-text q). 300 ms-debounced `router.get` with `replace + preserveState + preserveScroll`. `withCount('open_roles_count')`. Pagination at 20/page. Layout nav routes wired (Dashboard / Companies / Settings).
- **Step 10 — Create/Edit forms.** `AtsDetector` pure regex matcher (Greenhouse `boards.*`/`job-boards.*`/`embed?for=`, Lever, Ashby). Five invokable controllers. Shared `CompanyForm` React component with 400 ms-debounced live ATS detection on website/careers URL; doesn't clobber user-set values. Domain auto-derived from website host (strips `www.`). `COMPANY_ADDED` event emitted on store (atomic with the insert).
- **Step 11 — Detail page.** `ShowCompanyController` hydrates company, paginated roles (15/page), interleaved events+notes timeline (last 50), open/total counts. Hand-rolled accessible `Tabs` primitive (no new dep): full ARIA + arrow/Home/End keyboard nav.
- **Step 12 — Tags.** `AutocompleteTagsController` ranks by usage_count (subquery against `taggables`). `AttachTagController` is idempotent via `firstOrCreate` + `syncWithoutDetaching`. `DetachTagController` keeps the tag row for reuse. `TagInput` React widget with debounced suggestions, "Create 'foo'" item, Enter-to-create, X-to-detach. Partial Inertia reloads (`only: ['tags']`) so the rest of the Show page doesn't rebuild.
- **Step 13 — CSV import.** `CsvImporter` service (parse → detect mapping → preview → run). Caps at 5000 rows. Idempotent on domain match. ATS auto-detection during import when careers/website URL present. Tier/status values normalized (`a` → `A`, `In Process` → `in_process`, unknown → default). Three-step wizard with session-backed state.
- **Step 14 — Cmd-K palette.** `GlobalSearchController` queries the FTS columns via `to_tsquery('english', ?)` with per-token `:*` prefix matching. `ts_rank_cd` orders within group; 8-per-group cap. Tokenizer strips non-alphanum and tokens with `AND` semantics. `GlobalCommandPalette` mounted in `AuthenticatedLayout`: Cmd/Ctrl+K opens, Esc closes, arrow/Enter navigation, 120 ms-debounced fetch with abort-on-keystroke.
- **Landing page.** Replaced the placeholder white Welcome with a quiet utilitarian front door (see `resources/js/Pages/Welcome.tsx`).

## Deviations from PRD v3 (recorded inline in code/docs)

- **No per-source HTTP pool yet.** PRD §9 and the kickoff explicitly ask for concurrent fetching via Saloon pool with per-source concurrency caps. Phase 1 ships sequential iteration in `ScrapeOrchestrator` because at personal-tool scale (≤500 companies, 6 h cron) the marginal benefit doesn't justify the cross-scraper refactor (splitting `run()` into `buildRequest()` + `handleResponse()`). The swap point is commented at the top of `app/Scrapers/ScrapeOrchestrator.php`. **Owner decision documented; the contract isn't broken, just unexercised.**
- **No parser layer.** PRD §6.1 puts the full parsed-with-confidence pipeline in the ingestion section. Phase 1 only captures the structured fields each ATS gives us natively and stores the rest in `roles.raw` (jsonb) for Phase 3 to refine. The kickoff explicitly excluded parsing — followed the kickoff over the PRD.
- **No global concurrency ceiling enforced at runtime.** `config/scrapers.php` carries `global_concurrency: 30` but nothing reads it yet. Will be wired when the per-source pool lands.
- **Edit form skips tag management.** Tags are managed exclusively from the Show page's Overview tab to avoid mixing inline-saved tag changes with the rest of the form's transactional `useForm` state. UX call, not a constraint.
- **Switched the test DB from SQLite `:memory:` to Postgres at user request.** `phpunit.xml` points at `laravel_testing` on the dev `postgres` container. ParaTest creates `laravel_testing_test_{1..10}` per process. FTS migration guards still live on driver name as a safety net.
- **Saloon Connector property name `$appConfig` (not `$config`)** in `LeverConnector` — PHP 8.5 rejects redeclaring the trait-defined readonly `$config` property. Documented inline.

## Decisions worth carrying into Phase 2

- **Tags pivot omits `tenant_id` deliberately.** Both endpoints (Tag and Company/Role) are tenant-scoped, so pivot rows are tenant-isolated transitively. Re-introducing a pivot tenant_id later would need a migration plus `BelongsToTenant`-aware pivot inserts (the trait doesn't auto-fill pivot inserts).
- **Morph map is enforced (`Relation::enforceMorphMap`).** Adding new polymorphic targets without a map entry throws. Phase 2 aggregator listings tied to companies will need an entry if they ever become polymorphic.
- **Role uniqueness is `(tenant_id, source, external_id)`.** A given ATS posting id is globally unique within its source, so this works even when the same company exists across multiple ATS systems. Cross-source dedup (when the same role surfaces on Greenhouse and aggregator-listing scrapes) will need explicit linking in Phase 2 — Phase 1 treats them as distinct rows by design.
- **`ScraperRunResult::notModified` carries forward the etag/last_modified** so the orchestrator can keep the company's cache validators fresh even when nothing else changed. Don't lose this when the per-source pool refactor lands.
- **`AtsScraperInterface` is intentionally narrow.** `name()`, `concurrency()`, `run(Company)`. When the pool refactor lands, add `buildRequest()` + `handleResponse()` as optional methods (or split into a sub-interface) rather than redesigning the whole contract.
- **All write-side workflows pre-commit to one tenant via context.** Commands and HTTP middleware both. Avoid sneaking in a service that infers tenant from the row being mutated; if it slips, the `BelongsToTenant` observer will catch it at insert.
- **FTS uses `to_tsquery` with `:*` prefix tokens, not `websearch_to_tsquery`.** Gives autocomplete-feel on partial input ("stri" matches "Stripe") at the cost of slightly less natural language tolerance. The sanitizer strips non-alphanum before tokenizing so malformed input never reaches the parser.
- **The orchestrator writes `scrape_runs` for every attempt** — including `not_modified` and `error` rows. Phase 3 admin UI gets a free audit trail.

## Open follow-ups (deferred, not blockers)

- **Per-source HTTP pool refactor.** See deviation above. Probably ~half a day across three scrapers + orchestrator + tests.
- **Scheduler entry.** Nothing wires `scrape:all` to Laravel's scheduler yet. `routes/console.php` or a Cloud scheduled task once Wave F deploys. Cadence per PRD §6.2: A-tier 6 h, B-tier 12 h, C-tier 24 h. Phase 1 has no tier-aware cadence; the orchestrator iterates uniformly.
- **`scrape_run_artifacts` (raw HTTP blob retention).** PRD §11 says 7 days, configurable. Phase 1 only writes the summary row, not the raw bodies. Defer to Phase 3 with the parser/corrections UI.
- **No retry middleware on Saloon connectors.** Each scraper's `run()` catches exceptions and returns them in `errors`, but doesn't reissue on 429/5xx with exponential backoff per SOURCES.md. The config holds `retry_times`/`retry_sleep_ms`; nothing reads them yet.
- **Wave F (Laravel Cloud deploy) still deferred.** `cloud.yaml`, custom domain, secrets, scheduled task. Phase 0 leftover.
- **TS strict but no ESLint.** Inertia React pages compile cleanly under `tsc --noEmit` but there's no `npm run lint`. Phase 0 follow-up; still deferred.
- **Bundle now 387 KB minified.** Up from 540 KB pre-Phase-1 — Vite naturally code-split most of the new pages. `AuthenticatedLayout` is the heaviest chunk (~120 KB) because it now includes the command palette. Not addressing until a page actually feels slow.
- **`/companies/{company}` route is `int`-typed via route model binding** rather than the Codyssey-style "resolve in handler" pattern. Works cleanly because `BelongsToTenant` 404s foreign-tenant rows automatically. If we ever drop the global scope this becomes a leak; doc it in any future tenancy refactor.
- **Worktree branches `worktree-agent-*` still locked** by the agent runtime. Will release on a separate session.
- **`/.mcp.json` ships a `uidotsh` bearer token in plaintext** — same as Phase 0. Rotate before going public.

## Verification snapshot at the end of Phase 1

- **PHP test suite:** 199 tests, 881 assertions, parallel via ParaTest (10 processes), 1.9 s.
- **TypeScript:** `npx tsc --noEmit` clean.
- **Vite build:** green, 1.0 s. `app-*.js` ~387 KB minified, ~125 KB gzip. `AuthenticatedLayout-*.js` ~120 KB (palette included).
- **Pint:** `{"tool":"pint","result":"passed"}`.
- **Migrations:** 19 applied cleanly via `migrate:fresh`. Order: Phase 0's 13 + extend-roles, extend-companies, scrape_runs, tags, taggables, FTS.

## File map for orientation (new in Phase 1)

```
app/
├── Console/Commands/
│   ├── ScrapeAllCommand.php
│   └── ScrapeCompanyCommand.php
├── Enums/
│   ├── AtsType.php
│   ├── EventType.php
│   └── WorkplaceType.php
├── Http/
│   ├── Controllers/
│   │   ├── Companies/                  (Index, Create, Store, Edit, Update, Show, DetectAts)
│   │   ├── Import/                     (UploadForm, UploadCsv, MapForm, RunImport, CancelImport)
│   │   ├── Search/GlobalSearchController.php
│   │   └── Tags/                       (AutocompleteTags, AttachTag, DetachTag)
│   └── Requests/
│       ├── Companies/{Store,Update}CompanyRequest.php
│       └── Import/{UploadCsv,RunImport}Request.php
├── Imports/
│   └── CsvImporter.php
├── Models/
│   ├── ScrapeRun.php
│   └── Tag.php
├── Providers/
│   └── ScraperServiceProvider.php
└── Scrapers/
    ├── Ashby/{AshbyConnector, AshbyScraper, Requests/JobBoardRequest}.php
    ├── Contracts/AtsScraperInterface.php
    ├── Greenhouse/{GreenhouseConnector, GreenhouseScraper, Requests/BoardJobsRequest}.php
    ├── Lever/{LeverConnector, LeverScraper, Requests/PostingsRequest}.php
    ├── AtsDetector.php
    ├── ParsedRole.php
    ├── RoleDiffer.php
    ├── RoleDiffPlan.php
    ├── ScrapeOrchestrator.php
    ├── ScraperRegistry.php
    └── ScraperRunResult.php

config/scrapers.php
database/migrations/2026_05_18_00001[0-5]_*.php          (6 new migrations)
database/factories/{ScrapeRun,Tag}Factory.php

resources/js/
├── components/
│   ├── companies/{CompanyForm, TagInput}.tsx
│   ├── search/GlobalCommandPalette.tsx
│   └── ui/{badge, table, tabs}.tsx
└── Pages/
    ├── Companies/{Index, Create, Edit, Show}.tsx
    └── Import/{Upload, Map}.tsx

tests/
├── Feature/
│   ├── Companies/{IndexCompanies, CompanyForms, ShowCompany}Test.php
│   ├── Console/ScrapeCommandsTest.php
│   ├── Import/CsvImportTest.php
│   ├── Schema/Phase1SchemaTest.php
│   ├── Scrapers/{AshbyScraper, GreenhouseScraper, LeverScraper, RoleDiffer, ScrapeOrchestrator, ScraperRegistryBinding}Test.php
│   ├── Search/GlobalSearchTest.php
│   └── Tags/TagsTest.php
├── Fixtures/scrapers/{ashby, greenhouse, lever}/*.json
└── Unit/Scrapers/{AtsDetector, ScraperRegistry}Test.php
```

## Commit trail (newest first)

```
9f4a498  Phase 1 step 14: global Cmd/Ctrl-K search palette
241a1f0  Phase 1 step 13: CSV import wizard
95ea82a  Phase 1 step 12: Tags model + autocomplete + attach/detach UI
04680d3  Phase 1 step 11: Company detail page with Overview/Roles/Timeline tabs
22c6618  Phase 1 step 10: Company create/edit forms with ATS auto-detection
89ec91a  Phase 1 step 9:  Companies index page with URL-state filters
939283d  Phase 1 step 8:  scrape:company and scrape:all orchestrator commands
0bcf5da  Wire Greenhouse, Lever, Ashby scrapers into ScraperServiceProvider
f61f9e4  Merge step 6 (Ashby scraper) from worktree
26a5d9a  Merge step 5 (Lever scraper) from worktree
d7c6770  Merge step 4 (Greenhouse scraper) from worktree
2f6485f  Phase 1 step 5: Lever scraper
4fa585a  Phase 1 step 6: Ashby scraper
4fccfa4  Phase 1 step 4: Greenhouse scraper
76e584e  Phase 1 step 7: RoleDiffer service with idempotency guarantee
b61fd00  Phase 1 step 3: scraper interface, registry, and config scaffold
2ce7e4b  Phase 1 step 1: schema + tags + scrape runs + FTS + enforced morph map
```

---

**Next phase:** Phase 2 (Aggregators + Triage) per `docs/claude-code-phase-2-kickoff.md`. Recommended to start a fresh Claude Code session and paste the kickoff prompt; it explicitly reads this notes file.
