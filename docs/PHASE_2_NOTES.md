# Phase 2 Notes

**Date:** May 2026
**Ship criterion (PRD §10):** "I can review and process HN's monthly thread in 15 minutes flat."
**Status:** Mechanically complete on `main`. The full ingestion → triage → bookmark path is wired end-to-end. Locally runnable via `spin exec -T php php artisan aggregator:run-all --tenant=primary` and the `/discover` page. Scheduled cron runs configured but not yet exercised in prod (Wave F still deferred from Phase 0).

---

## What shipped

- **Step 1 — Schema.** Migrations for `aggregator_sources`, `aggregator_listings` (with denormalized parsed columns for filter performance — `parsed_company_name`, `parsed_role_title`, `parsed_seniority`, `parsed_remote_type`, `parsed_comp_min_usd`, `parsed_comp_max_usd`, `has_comp`, `stack_tags`, `normalized_company_key`, `normalized_role_key`, `location_bucket`), `dismissed_companies`, `bookmarks`. `scrape_runs.company_id` made nullable and `source_kind` ('ats' default | 'aggregator') added so the orchestrator can write one history table for both pipelines. Two string-backed enums: `AggregatorSourceType` (hackernews/remotive/himalayas/weworkremotely/remoteok) and `AggregatorListingStatus` (new/linked/auto_dismissed/overflow/duplicate/kept/dismissed/snoozed). Bookmarks polymorphic with morph key `aggregator_listing` enforced. `AggregatorSourcesSeeder` chained into `DatabaseSeeder`, idempotent per tenant.
- **Step 2 — Aggregator scraper contract.** `AggregatorScraperInterface` parallel to `AtsScraperInterface` but `run(AggregatorSource)` instead of `run(Company)`, returning a leaner `AggregatorRunResult` (no `closedCount`, no `notModified` — aggregators don't close listings, they only ingest). `AggregatorRegistry` scoped binding, `AggregatorOrchestrator` writes `scrape_runs` rows with `source_kind='aggregator'`, tracks `last_run_at` / `next_run_at` / `last_error` / `consecutive_failures` on the source. `config/scrapers.php` extended with an `aggregators` section enforcing concurrency 1 across the board per SOURCES.md guidance.
- **Step 3 — Parser pipeline framework.** `ExtractionSource` enum with `priority()` for tie-breaking (`EXPLICIT > INFERRED > DEFAULT > MISSING`). `ExtractedField` value object validating confidence ∈ [0.0, 1.0]. `SourceContext` carries `structured` + optional `bodyText` + `bodyHtml`. `Extractor` interface — pure, deterministic, returns one `ExtractedField`. `ParsedListing` field-keyed result holder with jsonb-shaped `toArray()`. `ParserPipeline` merges results: higher confidence wins, ExtractionSource priority breaks ties, first-registered wins on total tie.
- **Step 4 — Stack-tag dictionary.** `dictionaries/stack_tags.php` with ~180 canonical entries, each with optional `aliases` / `requires` (custom regex) / `context` (required-cue list) / `category`. False-positive trap handling for `go`, `r`, `c`, `d`, `c++`, `c#`, `f#`, `dotnet` — context cues required for body matches; lookaround-based patterns for symbol-tokens that `\b` doesn't bracket cleanly. `StackTagsExtractor` merges two channels (structured `tags[]` resolved via alias map = EXPLICIT 0.95; body+title pattern matches = INFERRED 0.6; mixed = EXPLICIT 0.85; nothing = MISSING). Output deduped + alphabetically sorted for deterministic tests.
- **Step 5 — Remaining 10 extractors.** `CompanyNameExtractor`, `RoleTitleExtractor`, `DisciplineExtractor` (10 ordered keyword rules, low-confidence `engineering` fallback), `SeniorityExtractor` (Himalayas structured array first; otherwise ordered title rules — `staff` beats `senior`, `lead` beats `senior`, etc.), `LocationExtractor`, `RegionExtractor` (worldwide/us/emea/apac/americas bucketed), `RemoteExtractor` (compound: type+timezone_restrictions+geo_restrictions; EXPLICIT 0.9 when Himalayas `timezoneRestrictions[]` are present), `CompensationExtractor` (compound: min/max/currency/interval/equity/min_usd/max_usd; four channels — Himalayas/RemoteOK/Remotive-salary-string/body-regex; hard-coded spot rates for USD/EUR/GBP/CAD/AUD/CHF/SEK/NOK/DKK/PLN), `EmploymentTypeExtractor`, `VisaSponsorshipExtractor` (negative-first to avoid "cannot sponsor" matching the positive `sponsor visas?` pattern), `ApplyMethodExtractor`.
- **Step 6 — HackerNews fixture suite + parser-pipeline fixture test.** `HackerNewsContextBuilder` (pure HTML-strip + first-line tokenizer; separator priority `|` → en-dash → em-dash → ` - `; tokens classified by URL/salary/remote-marker/location/stack shape; `is_remote` only flagged for genuinely remote tokens so hybrid/on-site fall through to `RemoteExtractor`'s regex). 15 fixtures under `tests/Fixtures/parsers/hackernews/` spanning every format variation from SOURCES.md §2.1 (canonical pipe / en-dash / em-dash / hyphen; $/€/£ currencies; comma-grouped numbers; open-ended salary; HTML entities; hybrid / on-site; multi-paragraph; intern; apply-by-email; fully-prose fallback). `HackerNewsParserFixturesTest` walks each fixture's `expected` block with a sparse assertion DSL (`field`, `field_include`, `field_contains`). The fixture pass surfaced three real bugs (RemoteExtractor short-circuiting on hybrid/onsite; missing standalone `EU` in RegionExtractor; missing `research engineer` in DisciplineExtractor's ml rule) which were fixed in step 6.
- **Step 7 — HackerNews scraper end-to-end.** `HackerNewsAlgoliaConnector` against `hn.algolia.com`; two Saloon Requests (`FindLatestThreadRequest` searches `tags=story,author_whoishiring`, `FetchThreadRequest` pulls the full thread tree in one call beating Firebase HN's recursive fetches). `HackerNewsScraper` filters per SOURCES.md §2.1 (skip OP `whoishiring`, deleted/null-text comments, anything < 100 stripped chars). `CompanyKeyNormalizer` shared utility (lowercase, strip legal suffixes Inc/LLC/Ltd/GmbH/Corp/Co/Corporation/Company/the, strip non-alphanumerics) introduced here, reused by steps 12–14. `AggregatorServiceProvider` binds `ParserPipeline` as a scoped service with all 12 extractors.
- **Step 8 — Remotive scraper + shared persister extraction.** `RemotiveConnector` + `RemoteJobsRequest` (`GET /api/remote-jobs?category=software-dev`). `RemotiveContextBuilder` passes Remotive's structured keys through unchanged — the extractors already key on the canonical names. `AggregatorListingPersister` extracted from `HackerNewsScraper` as a shared parse-and-persist tail: every aggregator scraper now only owns fetch + filter + context-building. Idempotent on `(source_id, external_id)`; reruns update rows in place while preserving any user-set status the triage UI may have written.
- **Steps 9–11 — Himalayas, We Work Remotely, RemoteOK (parallel agents).** Three worktree-isolated agents built in parallel against the Remotive template. Himalayas captures `timezoneRestrictions[]` at EXPLICIT 0.9 confidence (the headline feature for a CET-based user per SOURCES.md §2.3). We Work Remotely parses three RSS feeds (back-end / full-stack / programming) with a parameterized `CategoryFeedRequest`, in-process `seenGuids` dedup across feeds within one run, and a `Company: Role` title split. RemoteOK skips index 0 (metadata envelope) via `array_values + index check`, robust to a metadata object that has its own `id` field. One agent caught a brief drift (my brief said `<region>` → `structured['location']`, but `LocationExtractor`'s docblock specifies `structured.region` for We Work Remotely) and overrode it correctly — wallclock for 3 agents was ~10 min vs. ~25 min serial.
- **Step 12 — Auto-link via Jaro-Winkler.** `MatchCompanyAction` with hand-written Jaro-Winkler (no PHP built-in) + exact-key fast path. THRESHOLD = 0.85 hard-coded per PRD §11.2. On insert only (never on update), `AggregatorListingPersister` runs the match — if a tracked Company matches, sets `matched_company_id`, flips status to `LINKED`, writes one Event row (type `NEW_ROLE`, `role_id` null since there's no Role yet; `body` carries `{aggregator_listing_id, source_id}` as JSON). `occurred_at` honors `listing.posted_at` when present.
- **Step 13 — Auto-dismiss memory + dashboard indicator.** `DismissListingAction` (returns a `DismissResult` value object): increments `dismiss_count` for the normalized key, flips `auto_dismiss = true` on the threshold-crossing third dismissal, exposes a one-shot `ruleFlippedToAutoDismiss` signal so the UI can flash exactly once. Idempotent — re-dismissing an already-DISMISSED listing is a no-op. `AggregatorListingPersister` gates new inserts against `dismissed_companies` — if the normalized key has `auto_dismiss = true`, the listing arrives `AUTO_DISMISSED` and skips auto-link. Exact-key match only: "Globex" with auto-dismiss does not gate "Globex Robotics". `ActiveAutoDismissRulesQuery` powers the dashboard banner (quiet dashed-border row, top 5 employers with "+N more" overflow, "Manage" link to `/discover/sources`).
- **Step 14 — Cross-source dedup + volume cap.** Inside the persister, after auto-dismiss and before auto-link: if a non-dismissed/non-duplicate listing within the 14-day window shares `(normalized_company_key, normalized_role_key, location_bucket)`, the new listing's status becomes `DUPLICATE` with `duplicate_of_id` pointing at the earliest visible canonical. Dismissed canonicals don't block fresh-NEW — if the user has actively hidden one source's listing, the same role from another source still arrives for review. DUPLICATE listings skip auto-link (no NEW_ROLE event noise). `VolumeCapAction` with constructor-injected thresholds (defaults 200/50): when a run inserts more than `triggerThreshold` NEW listings, demotes everything below the top `keepTop` by signal (has_comp +1, parsed_remote_type='remote' +1; ties broken by posted_at desc then id asc) to OVERFLOW. Scrapers track newly-created IDs locally; `Persister::capRun(array)` delegates to the action at end-of-run. `Persister::upsert()` signature changed `bool` → `?int` (listing ID on insert, null on update) across 5 scraper call sites.
- **Step 15 — `/discover` triage queue.** `KeepListingAction` (idempotent status flip to `KEPT`) and `SnoozeListingAction` (status `SNOOZED` + `snoozed_until = now + N days`, default 7, picker accepts 1–90). `IndexController` at `GET /discover` with URL-state filters (status tab, source, has_comp, remote_only, free-text q over parsed_company_name + parsed_role_title), paginated 25/page, ordered posted_at desc + id desc. Three action endpoints under `/discover/{listing}/{keep,dismiss,snooze}`, dismiss flashes the auto-dismiss threshold message on the third manual dismissal. React page in `resources/js/Pages/Discover/Index.tsx`: status tabs across the top, filter bar, card list with role title + seniority + company + source badge + remote type + region + comp range + top 8 stack tags. Keyboard nav: `J` / `↓` next, `↑` previous, `K` / `Enter` keep, `X` dismiss, `S` snooze, `Esc` clear focus. Inputs suppress the global handler. Action submissions via `router.post` with `preserveScroll + preserveState + only: ['listings']`.
- **Step 16 — Bookmarks, sources admin, scheduler wiring.** `BookmarkListingAction` (idempotent; re-bookmark resets `expires_warned_at` and extends `expires_at`). `B` keybinding on the Discover card list. `GET /bookmarks` with sort options (added / posted / expires), hydrates the underlying listing for each row. `DELETE /bookmarks/{bookmark}` to remove. `bookmarks:warn-expiring` and `bookmarks:expire` console commands (T-3 warn, hard-delete on expiry per PRD §11.2). `GET /discover/sources` stacks two cards: aggregator sources (enable/disable toggle, run-now button, last/next run + consecutive failures + last_error) and auto-dismiss rules (Revoke clears the flag, preserves the count audit). `aggregator:run {source} --tenant=` and `aggregator:run-all --tenant=` artisan commands with JSON cron-friendly output. `routes/console.php` schedules: HackerNews monthly on the 3rd + 5th at 07:00, the other four sources daily at 06:30, `bookmarks:warn-expiring` daily at 06:00, `bookmarks:expire` daily at 06:05. `schedule:list` verified all eight entries.

## Deviations from PRD (and from my own kickoff response)

- **Renamed `HN` → `HackerNews` and `WWR` → `WeWorkRemotely` everywhere mid-Phase.** Owner request. Enum cases `HACKERNEWS`/`WEWORKREMOTELY`, backing values `'hackernews'`/`'weworkremotely'`, namespace `App\Scrapers\Aggregator\HackerNews`. Done in commit `d4d07d6` after step 5; all subsequent steps assume the new names.
- **Keep is a plain status flip; no "link to existing company or create new" modal.** PRD §6.4.3 describes a modal flow with autocomplete + create-from-listing. Phase 2 ships Keep = `status='kept'` and nothing else. The autocomplete + create pipeline is heavier than it looks (it overlaps with Phase 5 Pipeline work) and isn't required to clear the triage queue, which is what the ship criterion measures. Promotion to a tracked Company stays a Phase 5+ workflow.
- **No optimistic-update reducer or undo toasts on `/discover`.** My kickoff response described "optimistic actions with undo toasts for 5–10 seconds". Phase 2 ships server-authoritative actions via Inertia partial reloads — at personal-tool scale a Discover action round-trips in ~100ms which feels fine. The reducer + undo layer is a clean retrofit if it ever bites in practice.
- **Stack overlap dropped from volume-cap signal scoring.** My kickoff response listed three signal components (has_comp, is_remote, preferred-stack overlap). Stack overlap requires storing user's preferred tags somewhere, and there is no settings infrastructure for that in v1. Cap uses has_comp + is_remote only; preferred-stack scoring is a clean addition once a preferences page exists (Phase 6 area).
- **"View raw" expander on cards is deferred.** The PRD-described raw-posting disclosure under each card. `listing.raw` is on the row already; the inspector UI lands with the Phase 3 parser-corrections work.
- **Bulk actions deferred.** PRD §6.4.3 lists "dismiss multiple / dismiss all from this company / dismiss all matching keyword". Step 15 ships single-card actions only, which is what clears the ship criterion. Bulk operations are Phase 6 polish.
- **Synchronous "run now" on the sources admin.** PRD doesn't specify sync vs. queued. At <30s per HackerNews fetch the HTTP block is acceptable; if fetches lengthen, push to a queued job and Inertia-poll the source row. Documented inline.
- **Bookmark sorting in PHP collection, not SQL.** `BookmarksIndex` paginates the bookmarks then sorts the hydrated listings client-side. Works fine at < a few hundred bookmarks; if it bites, denormalize sort keys onto the bookmark row or push the sort into SQL.
- **Scheduler picks the first tenant in the DB.** Single-tenant v1; the `routes/console.php` closure resolves `Tenant::orderBy('id')->value('slug')`. When (if) multi-tenancy lands, swap for a tenant-aware iterator.

## Decisions worth carrying into Phase 3

- **`AggregatorListingPersister` is the universal parse-and-persist tail.** All five aggregator scrapers (and any future ones) only own fetch + filter + context-build; the persister handles parser pipeline, denormalization, idempotency, auto-dismiss / dedup / auto-link gates. The shared service was extracted in step 8 — getting it right meant the parallel-agent steps 9–11 only needed to write ~150 LOC per scraper.
- **`Persister::upsert()` returns `?int` (listing ID on create, null on update).** Scrapers track newly-created IDs locally and call `Persister::capRun($ids)` once at end-of-run. This avoids stateful service singletons under Octane and keeps the cap action stateless. Don't regress to a bool return without thinking through what state lives where.
- **Gate order: AUTO_DISMISSED → DUPLICATE → LINKED → NEW.** Each gate runs on insert only, never on update. The order is deliberate — auto-dismiss represents explicit user intent (highest priority); dedup is "the user already has this in their queue"; auto-link is "send this to a tracked company's timeline"; NEW is fall-through. Auto-link skips for AUTO_DISMISSED and DUPLICATE listings so the company timeline doesn't get NEW_ROLE noise for rows the user will never see.
- **Cross-source dedup excludes dismissed/auto-dismissed canonicals.** If the user has actively hidden a source's listing, the same role from another source arrives fresh-NEW so they don't miss it. Trade-off: the dedup window can be defeated by dismissing. Acceptable for a personal tool.
- **Auto-dismiss is exact-key match only.** "Globex" with `auto_dismiss=true` does not gate "Globex Robotics". Fuzzy matching for auto-dismiss would be unsafe — likelier to gate roles you actually want than to suppress what you don't. Auto-link uses fuzzy (Jaro-Winkler ≥ 0.85) precisely because the failure mode there (a missed link, surfaced in triage) is benign; auto-dismiss's failure mode (a hidden role you wanted) is not.
- **`CompanyKeyNormalizer` is the canonical employer-key source of truth.** Used by aggregator_listings persistence, MatchCompanyAction, DismissedCompany lookup, and the volume cap's grouping. Strip legal suffixes (`Inc / LLC / Ltd / GmbH / Corp / Co / Corporation / Company / the`), strip non-alphanumerics, lowercase. Preserve original spelling in `parsed_company_name`. Don't normalize aggressively elsewhere — anywhere we compare employers across rows, route through this.
- **`is_remote` flag from context builders is for genuine remote markers only.** Step 6 fixed a bug where the HackerNewsContextBuilder set `is_remote = true` for `Hybrid` / `On-site` location tokens, short-circuiting RemoteExtractor to `'remote'`. Lesson: a builder's structured-key writes should mean what the downstream extractor thinks they mean.
- **Fixtures-driven parser tests caught three real bugs before the HN scraper shipped.** The step-6 fixture pass surfaced the `is_remote` short-circuit, missing standalone `EU` region, and missing `research engineer` ml-discipline pattern. Keep this pattern — fixtures *before* the scraper code that feeds them, so the parser is the contract.
- **Worktree-based parallel agents work well for genuinely independent fan-out.** Steps 9–11 (three aggregator scrapers, no shared state beyond the persister) ran in ~10 min wallclock vs. ~25 min serial. The one coordination point — `AggregatorServiceProvider::boot` registration — was reconciled manually at merge. Don't try to parallelize work that mutates shared logic.
- **PHP has no built-in Jaro-Winkler.** Hand-written in `MatchCompanyAction::jaroWinkler`. Standard Jaro distance + Winkler prefix bonus (`p=0.1`, capped at 4 leading common chars). Don't reach for `levenshtein` — too coarse on company names; "Stripe" / "Stripe.com" scores 0.93 on JW but Levenshtein doesn't carry the prefix-match intuition we want.
- **Confidence is honest.** Every extractor reports a defensible number. 0.4 from an EXPLICIT structured-field match beats 0.9 from a body-regex guess — the UI uses confidence to surface what needs review, and the value evaporates if we lie.
- **No LLM calls anywhere in the parser.** Per PRD §6.1.4. Deterministic, testable, free. The extractor framework would technically support an LLM-backed extractor today; resist the temptation.

## Open follow-ups (deferred, not blockers)

- **Wave F (Laravel Cloud deploy) still deferred.** `cloud.yaml`, custom domain, secrets list, scheduled task wired to `php artisan schedule:run`. Carried over from Phase 0/1.
- **No per-source HTTP pool yet.** Phase 1 deferred it; Phase 2 didn't touch it. Aggregators all run concurrency=1 so the per-source pool doesn't even apply here — but the ATS-scraper deferral from Phase 1 is still on the table.
- **Snooze re-arming.** `SnoozeListingAction` sets `snoozed_until`; nothing currently flips snoozed listings back to `NEW` when the time elapses. Either a `listings:wake-snoozed` console command (daily) or lazy on-read at the Discover index. Step 16 didn't ship it — defer to Phase 3.
- **Saloon retry middleware on aggregator connectors.** Config values exist (`retry_times`, `retry_sleep_ms`), nothing reads them yet. Same gap Phase 1 documented for ATS scrapers.
- **Parser correction UI.** PRD §6.1.5 — the inline-correction loop, `ParseCorrection` table, "save as test fixture" workflow. Phase 3 territory.
- **Scrape run history viewer at `/admin/runs`.** Phase 3 per the kickoff doc. The `scrape_runs` rows already capture aggregator runs via `source_kind='aggregator'`; the admin page is the missing piece.
- **Bookmark sort in PHP collection.** Acceptable at small scale; if the table grows, denormalize sort keys onto the bookmark row.
- **Auto-link confidence threshold is hard-coded.** PRD §11.2 promised promotion to a Setting if false-positives/negatives bite. Watch this in real use.
- **The dashboard auto-dismiss banner links to `/discover/sources` but the page intersperses sources and rules.** Works, but a focused "review and revoke" surface might read better. Consider in Phase 6 polish.
- **No bulk actions in `/discover`.** Single-card actions cover the ship criterion. Phase 6 polish.
- **No "View raw" expander.** Phase 3 with the parser inspector.
- **`/.mcp.json` still ships a `uidotsh` bearer token in plaintext.** Phase 0 / Phase 1 carry-over. Rotate before public.
- **`worktree-agent-*` branches and worktrees are cleaned up.** Done at end of steps 9–11.
- **The `sources` table from Phase 0 scaffold is still unused.** It was intended for discovery sources but I introduced `aggregator_sources` instead. Cleanup migration whenever convenient.

## Verification snapshot at the end of Phase 2

- **PHP test suite:** 467 tests, 1779 assertions, parallel via ParaTest (10 processes), ~52 s.
- **TypeScript:** `npx tsc --noEmit` clean.
- **Vite build:** green, ~720 ms. `app-*.js` ~389 KB minified, ~125 KB gzip. `AuthenticatedLayout-*.js` ~120 KB (nav, command palette, etc.).
- **Pint:** `{"tool":"pint","result":"passed"}`.
- **Migrations:** 24 applied cleanly via `migrate:fresh`. Phase 1's 19 + Phase 2's 5 (add `source_kind` to `scrape_runs`, create `aggregator_sources`, create `aggregator_listings`, create `dismissed_companies`, create `bookmarks`).
- **Schedule:** `schedule:list` shows 8 entries (HackerNews 3rd / HackerNews 5th / 4× daily-aggregator / bookmarks-warn / bookmarks-expire).

## File map for orientation (new in Phase 2)

```
app/
├── Console/Commands/
│   ├── AggregatorRunAllCommand.php
│   ├── AggregatorRunCommand.php
│   ├── BookmarksExpireCommand.php
│   └── BookmarksWarnExpiringCommand.php
├── Enums/
│   ├── AggregatorListingStatus.php
│   └── AggregatorSourceType.php
├── Http/
│   ├── Controllers/
│   │   ├── Bookmarks/                       (Create, Delete, Index)
│   │   ├── Discover/                        (Index, Keep, Dismiss, Snooze,
│   │   │                                     BookmarkListing,
│   │   │                                     Sources/{Index, Toggle, Run, RevokeAutoDismiss})
│   │   └── DashboardController.php          (now shares active_auto_dismiss_rules)
│   └── Requests/Discover/
│       └── SnoozeListingRequest.php
├── Models/
│   ├── AggregatorListing.php
│   ├── AggregatorSource.php
│   ├── Bookmark.php
│   └── DismissedCompany.php
├── Providers/
│   └── AggregatorServiceProvider.php        (scoped registry + 12-extractor pipeline)
└── Scrapers/Aggregator/
    ├── AggregatorListingPersister.php       (gated parse-and-persist tail)
    ├── AggregatorOrchestrator.php
    ├── AggregatorRegistry.php
    ├── AggregatorRunResult.php
    ├── ActiveAutoDismissRulesQuery.php
    ├── BookmarkListingAction.php
    ├── CompanyKeyNormalizer.php
    ├── Contracts/AggregatorScraperInterface.php
    ├── DismissListingAction.php             (+ DismissResult)
    ├── KeepListingAction.php
    ├── MatchCompanyAction.php               (Jaro-Winkler scorer)
    ├── SnoozeListingAction.php
    ├── VolumeCapAction.php
    ├── HackerNews/                          (Connector, ContextBuilder, Scraper, Requests/*)
    ├── Himalayas/                           (Connector, ContextBuilder, Scraper, Requests/*)
    ├── Parser/
    │   ├── Contracts/Extractor.php
    │   ├── ExtractedField.php
    │   ├── ExtractionSource.php
    │   ├── Extractors/                      (12 extractors: CompanyName, RoleTitle,
    │   │                                     Discipline, Seniority, Location, Region,
    │   │                                     Remote, Compensation, StackTags,
    │   │                                     EmploymentType, VisaSponsorship, ApplyMethod)
    │   ├── ParsedListing.php
    │   ├── ParserPipeline.php
    │   └── SourceContext.php
    ├── RemoteOk/                            (Connector, ContextBuilder, Scraper, Requests/*)
    ├── Remotive/                            (Connector, ContextBuilder, Scraper, Requests/*)
    └── WeWorkRemotely/                      (Connector, ContextBuilder, Scraper, Requests/*)

config/scrapers.php                          (extended with aggregators section)
database/migrations/2026_05_18_00002[0-4]_*.php   (5 new migrations)
database/factories/{AggregatorListing,AggregatorSource,Bookmark,DismissedCompany}Factory.php
database/seeders/AggregatorSourcesSeeder.php

dictionaries/
└── stack_tags.php                           (~180 canonical entries)

resources/js/Pages/
├── Bookmarks/Index.tsx
└── Discover/
    ├── Index.tsx
    └── Sources/Index.tsx

routes/
├── console.php                              (8 scheduled entries)
└── web.php                                  (discover.*, bookmarks.*, discover.sources.*)

tests/
├── Feature/
│   ├── Console/                             (AggregatorCommands, BookmarkLifecycle)
│   ├── Discover/                            (Discover, SourcesAndBookmarks, AutoDismiss,
│   │                                         AggregatorAutoLink, DedupAndVolumeCap)
│   ├── Schema/Phase2SchemaTest.php
│   └── Scrapers/Aggregator/                 (per-scraper feature tests, AggregatorOrchestrator,
│                                             AggregatorAutoLink, AutoDismiss, DedupAndVolumeCap,
│                                             HackerNewsParserFixtures)
├── Fixtures/
│   ├── parsers/hackernews/                  (15 HackerNews fixtures)
│   └── scrapers/aggregator/
│       ├── himalayas/
│       ├── remoteok/
│       ├── remotive/
│       └── weworkremotely/                  (3 RSS fixtures)
└── Unit/Scrapers/Aggregator/
    ├── AggregatorRegistryTest.php
    ├── MatchCompanyActionTest.php
    └── Parser/                              (ExtractedField, ParserPipeline, and the 12
                                              extractor unit tests, grouped into
                                              IdentityExtractors, GeoExtractors,
                                              CompensationExtractor, MetadataExtractors,
                                              StackTagsExtractor)
```

## Commit trail (newest first)

```
df062e1  Phase 2 step 16: bookmarks, /discover/sources admin, scheduler wiring
8a06d37  Phase 2 step 15: /discover triage queue
ec25c77  Phase 2 step 14: cross-source dedup + volume cap
9645e59  Phase 2 step 13: auto-dismiss memory + dashboard indicator
6fc6311  Phase 2 step 12: auto-link parsed listings to tracked companies
6472069  Phase 2 steps 9-11: Himalayas, We Work Remotely, RemoteOK scrapers
dd8a448  Phase 2 step 8:  Remotive scraper + extract shared listing-persister
a473f44  Phase 2 step 7:  HackerNews scraper end-to-end
720effe  Phase 2 step 6:  HackerNews fixture suite + parser-pipeline fixture test
d4d07d6  Rename HN -> HackerNews, WWR -> WeWorkRemotely (no abbreviations)
acefb46  Phase 2 step 5:  10 parser extractors covering the canonical field set
81ac36b  Phase 2 step 4:  stack-tag dictionary + extractor with false-positive guardrails
4ec958b  Phase 2 step 3:  parser pipeline framework + extractor contract
e7fb579  Phase 2 step 2:  aggregator scraper contract + registry + orchestrator
8b55fa6  Phase 2 step 1:  aggregator schema + tenancy-scoped tables
```

---

**Next phase:** Phase 3 (Parser polish + corrections) per `docs/claude-code-phase-3-kickoff.md`. The parser pipeline + extractors built in Phase 2 are the substrate; Phase 3 layers inline correction UI, `ParseCorrection` table, the "save as fixture" workflow, parser stats dashboard at `/admin/parser`, raw scraper artifact retention (7 days), and the `/admin/runs` history viewer.
