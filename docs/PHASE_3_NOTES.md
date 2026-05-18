# Phase 3 Notes

**Date:** May 2026
**Ship criterion (PRD §10):** "When I correct a bad parse, the platform learns from it; I can build and save complex filter combinations."
**Status:** Met. Inline correction → ParseCorrection row → updated `parsed` jsonb + denormalized columns → fixture export → `parser:reparse-all` preserves the correction. Verified end-to-end against 694 live listings.

---

## What shipped

- **Step 2 — `parse_corrections` table + `parser_version` column + widened HN-overflowing columns.** Tenant-scoped append-only audit log keyed by listing + field. `old_value` / `new_value` as jsonb so non-scalar slots (`remote`, `compensation`) round-trip cleanly. `parser_version` (unsigned int, default 1) on `aggregator_listings` so step 10's bulk command can skip already-current rows. Widened `parsed_company_name`, `parsed_role_title`, `normalized_*_key`, `location_bucket` from `varchar(255)` → `text` after the first live scrape blew the limit on one HN comment.
- **Step 3 — `parsedField()` helper + jsonb round-trip test.** Phase 2 already wrote the per-field `{value, confidence, source, evidence}` shape into `aggregator_listings.parsed`. Step 3 pinned that contract with a feature test and added a small accessor on the model so the correction UI never has to know about the jsonb shape. Documented PG quirk: `0.0` round-trips as `0` (int) and key order is not preserved — round-trip tests use `assertEquals` field-by-field, not `assertSame` on the whole blob.
- **Step 4 — inline correction UI on `/discover`.** Every parsed pill on a Discover card is an `EditablePill`. One click opens a `CorrectionEditor` directly beneath the card showing current value, confidence badge, source flag, parser-evidence snippet, and a `View raw` toggle for `listing.raw`. Save → POST `/discover/{listing}/corrections` → `ApplyCorrectionAction` writes a `ParseCorrection` row, rewrites the `parsed` jsonb slot to `{value, confidence: 1.0, source: explicit, evidence: user_correction}` (preserving sibling sub-keys for compound slots like `remote` and `compensation`), and syncs the denormalized column. Idempotent on identical-value saves. Form-request: array-based rules, per-field typed validation. Tests: 9 cases covering happy paths, compound-slot preservation, `has_comp` recompute, stack-tags blank/dedup, the no-op, validation rejects, tenant scope, auth.
- **Step 5 — "save as test fixture" one-click.** Button on every Discover card and adjacent to the correction editor → POST `/discover/{listing}/save-fixture` → `SaveFixtureAction` writes `tests/Fixtures/parsers/{source}/{slug}.json` with `{source, external_id, raw, structured, expected}`. Slug = `{source}-{external_id}` when alphanumeric and ≤24 chars, else `{source}-{first-12-md5}`. `expected` uses dot-notation keys (`compensation.min_usd`, `stack_tags_include`, `remote.type`) so exports drop into the existing parser-fixture test format. `HackerNewsParserFixturesTest` tightened to skip files lacking the `{comment_id, text}` shape so step-5 exports landing in `hackernews/` don't confuse it. Extracted `SourceContextFactory` so a stored listing can be re-keyed through its scraper's pure context builder.
- **Step 6 — re-parse single listing on demand.** POST `/discover/{listing}/reparse[?force=1]` → `ReparseAction` rebuilds the SourceContext via `SourceContextFactory`, runs `ParserPipeline::parse`, projects the result into both `parsed` jsonb + denormalized columns, then overlays existing `ParseCorrection` rows (corrections win; `force=1` skips the overlay). Audit rows aren't duplicated by a re-parse. UI: `⌄ more` chevron menu next to `save fixture` with re-parse / re-parse (force) options. Five tests cover the plain path, correction preservation, `force=1`, idempotency, auth.
- **Step 7 — raw scraper artifact retention.** `scrape_run_artifacts` table (tenant + run + source/company + connector + endpoint + http_status + path + size + sha256 + retained_until). Filesystem storage under `storage/scrape-artifacts/{yyyy-mm-dd}/{source-slug}/{connector}/` with gzipped bodies; content-type-driven extensions (`.json`/`.xml`/`.html`/`.bin`). `RecordResponseArtifact` middleware attached to all 5 aggregator connectors via Saloon v4's `Connector::boot(PendingRequest)` (v4 does not have a `defaultMiddleware()` method; that's a v2/v3 idiom). `ScrapeArtifactContext` scoped singleton carries the in-flight `scrape_runs.id` + source/company context into the middleware; orphan rows (no context yet) are still written with `scrape_run_id=null`. `scrape-artifacts:purge` daily 03:00 UTC. Retention default 7 days, configurable via `scrapers.artifact_retention_days`.
- **Step 8 — `/admin/runs` scrape history viewer.** Inertia page with URL-state filters (date range + presets, `source_kind`, `source_id`, `status`) and quick chips ("Failed in 24h", "Today", "All sources"). 25/page. Source-health strip at top surfaces any `aggregator_source` with `consecutive_failures ≥ 1` and any recently-failed ATS scrape — one click filters the list. Drill-in: run summary, error log (truncated 1 KB with expander), artifacts table linking step 7's rows. `/admin/runs/artifacts/{artifact}/download` streams the gzipped body with `Content-Encoding: gzip` when accepted, otherwise inflates; 404 when `retained_until < now`. Single-user (no admin-role middleware).
- **Step 9 — `/admin/parser` stats dashboard + `parser_confidence_daily` roll-up.** Table per `(tenant, source, field, day)` with `n` / `n_missing` / `p10` / `p50` / `p90` / `n_corrections`. `parser:rollup-confidence` uses Postgres `percentile_cont(0.x) WITHIN GROUP` over `(parsed->field->>'confidence')::float`. Daily 02:30 UTC, ahead of the 03:00 artifact purge. Dashboard: heat-map matrix (source × 12 canonical fields) with thresholds **red < 0.40, amber 0.40–0.70, green ≥ 0.70** — the spec's 0.50/0.75 bands would have turned most cells amber-or-red because empirically the distribution is sharply bimodal (either ~0 missing or 0.70–0.95 matched, almost nothing between). Top 10 lowest-confidence fields ranked by `p50 * log(n+1)` so a low-confidence field with 1 row doesn't outrank one with 100. Top 10 corrections per `(source, field)`. Recent corrections list. "Re-parse all" button requires typed `confirm=YES`. Cells link to `/discover?status=new&source={X}` (no dedicated drill-in surface; the existing inspector is the right place for per-listing follow-through).
- **Step 10 — `parser:reparse-all` + `parser:reparse --source`.** Chunked Eloquent iteration (default 1000/chunk, per-chunk transactions). Skips listings whose `parser_version` equals the target. For each row: rebuild SourceContext, run pipeline, write parsed + denormalized columns, then overlay existing `ParseCorrection` rows (corrections win unless `--force-corrections`). Increments `parser_version` to the new target on every processed row. `parser_runs` checkpoint table tracks `target_version` / `from_id` / `to_id` / `processed` / `started_at` / `finished_at` / `force_corrections` / `source_filter` so `--from=ID` resumes after graceful Ctrl-C. `ReparseAction` gained a `reparseWithoutTransaction(AggregatorListing, bool)` sibling so the bulk command's chunk-level transaction isn't doubled up. Real-data benchmark: **694 listings in 1.1 s** (~700 rows/sec, single-threaded).
- **Step 11 — `scrape:backfill --source --days`.** HN: re-pulls last N monthly Who-Is-Hiring threads via new `FindHistoricalThreadsRequest` against `/api/v1/search_by_date` (sorted by `created_at_i` desc, `hitsPerPage=N` gives last N months in order). Existing `FindLatestThreadRequest` untouched. Remotive / RemoteOK / WWR / Himalayas: realtime feeds with no API history — the command replays from `scrape_run_artifacts` within the 7-day retention window. Gunzips the stored body, runs through the source's context builder, persists via `AggregatorListingPersister`. Days older than retention log `out_of_retention` and skip. JSON output: `source / days_requested / days_processed / threads_pulled / new_listings / updated_listings / out_of_retention / duration_ms`.
- **Step 12 — advanced filtering + Saved Views (partial — Companies only).** `saved_views(tenant, user, name, entity, filters jsonb, sort_order, is_default)`. Companies list now has multi-select chips for tier/status/ats_type, `last_checked_at` preset chips (1d/7d/30d/older), `has_open_roles` computed boolean. Quality score / stage / size / remote_policy / primary_stack / recently_funded are deferred-to-Phase-4 placeholders; the filter parser accepts the keys so saved views round-trip cleanly when those signals land. Saved-view CRUD (`POST /saved-views`, `PATCH /saved-views/{view}`, `DELETE /saved-views/{view}`), shared Inertia prop on `AuthenticatedLayout`, a "Views" dropdown in the desktop top nav (no left rail exists today) and a Views section in the mobile drawer. Drag-to-reorder via `@dnd-kit/sortable`. Default-view auto-load on bare `/companies`. Defensive degradation: unknown filter keys ignored, never 500.

## Deviations from PRD (and from the kickoff plan)

- **Step 12 ships /companies only.** No `/roles` advanced filtering (no Roles index controller exists yet — Phase 1 didn't build one); no `/discover` saved views (module exists, but extending Discover's existing filter parsing was outside the rebase scope when Agent C's worktree turned out to be on a pre-Phase-2 base). `SavedView::VALID_ENTITIES` reserves `roles` and `discover` so the slots land cleanly when those surfaces exist.
- **Heat-map thresholds shifted.** Kickoff implied red < 0.5 / amber 0.5–0.75 / green > 0.75. Live empirical distribution is bimodal, so the dashboard uses red < 0.40 / amber 0.40–0.70 / green ≥ 0.70 to keep "matched" fields green and "missing" fields red without an amber sea.
- **No dedicated `/admin/parser/{source}/{field}` drill-in.** Cells link to `/discover?status=new&source={X}` instead. The existing inspector + correction UI in Discover is the right place for per-listing follow-through; a dedicated drill-in adds another surface to maintain for marginal incremental value.
- **Stub `ParserReparseAllCommand` from step 9 was replaced by step 10.** Step 9 needed something to dispatch from the "Re-parse all" button while step 10 was still in flight; the real implementation arrived in step 10 and the merge picked the real version.
- **Step 6's correction-overlay duplicates three small helpers.** `parsedSlotKey`, `mergeIntoSlotValue`, `syncDenormalizedColumn` live on both `ApplyCorrectionAction` (writes audit row, represents a new correction) and `ReparseAction::overlayCorrections` (replays already-recorded corrections without touching the audit log). The two are deliberately separate — extracting a shared collaborator would need a thought-through seam. Flagged in step 6's commit body and deferred.
- **Seniority correction editor is free-text.** A select dropdown bound to the known values (junior/mid/senior/staff/principal/intern) would be friendlier. Not regressing the field; just a polish backlog item.
- **`parser:reparse-all` requires `--tenant=<slug>`.** In a single-tenant deployment this is friction; could default to the single tenant when only one exists. Documented but not changed.

## Decisions worth carrying into Phase 4

- **`parser_version` is a row property, not a global one.** The bulk command pins a target version at start (`max(parser_version) + 1`) and increments every processed row to that target. Subsequent invocations compute `max(parser_version)` again. This makes interrupt-resume cheap: re-running picks up the unfinished rows at the previously-pinned target. Don't migrate to a global "current parser version" config — the per-row counter is what makes "skip current rows" cheap and resumable.
- **Corrections always win on re-parse unless `--force-corrections`.** The overlay step is the contract; corrections are a permanent override of the parser's automatic output until the user explicitly forces them back. Future Phase 4 signals (quality_score, ghost flag, etc.) computed from the parser must read post-overlay values.
- **`scrape_run_artifacts` is not `BelongsToTenant`.** Intentional: orphan rows (written before the orchestrator set a context) have no tenant, and the retention sweep + purge must see every row. The model has a comment explaining this. Do not add the global scope without a plan for orphans + the sweep.
- **Roll-up table is the dashboard's only data source.** Sub-200ms target. Never aggregate raw `aggregator_listings.parsed` at request time. Empty-state on the page links to a "Run rollup now" button. If a Phase 4 dashboard needs a different aggregate, build a sibling roll-up table next to `parser_confidence_daily` rather than ad-hoc raw queries.
- **Heat-map color bands match the empirical distribution, not theoretical thresholds.** When confidence scoring changes in a future patch, revisit the bands rather than letting the dashboard misrepresent the data.
- **Discover Inertia partial reloads must use `preserveScroll + preserveState + only: [...]`.** Found by the dashboard-blank-page debug — `only: ['listings']` on the Discover action POST left other pages' destructured props undefined when the user navigated away. Dashboard now defaults its props at the destructure to be defensive; future pages should do the same.
- **`pnpm run build` runs a dep-status check that fires `pnpm install`.** With `minimum-release-age=7d`, an in-progress dependency bump (`@typescript-eslint` and friends pushed today) will fail that check. Two escapes: `pnpm exec vite build` (skips the check) or `node node_modules/vite/bin/vite.js build`. Document this in the runbook when packages get bumped.
- **Octane caches the Vite manifest in memory at boot.** When `pnpm run build` regenerates `public/build/manifest.json`, the running PHP container still serves the old hashes until `docker restart vantagejobs-php-1` (or `php artisan octane:reload` if it actually reloads; on this stack it sometimes doesn't). Production won't hit this — the deploy will boot fresh — but local dev does after every build.

## Open follow-ups (deferred, not blockers)

- **Wave F (Laravel Cloud deploy) still deferred.** `cloud.yaml`, custom domain, secrets list, scheduled task wired to `php artisan schedule:run`. Carry-over from Phase 0/1/2.
- **Roles index controller + page.** Once it exists, Roles advanced filtering and Saved Views for `roles` snap into place — entity slot already reserved.
- **Discover advanced filtering + Saved Views parity.** Module exists; needs the `discover` entity slot wired and the existing URL-state filter parser refactored to use a shared whitelist value-object pattern (`CompanyListFilters` is the reference).
- **Snooze re-arming.** `SnoozeListingAction` sets `snoozed_until`; nothing currently flips snoozed listings back to `NEW` when time elapses. Still deferred from Phase 2.
- **Saloon retry middleware on aggregator connectors.** Config values exist; nothing reads them. Same gap Phase 1 and Phase 2 documented.
- **`visa_sponsorship` extractor essentially never matches.** Across all 5 sources the field is at confidence 0 in roll-up. Either the extractor needs revisiting, or visa info is genuinely absent from these sources and the field should be deprioritized in the heat-map.
- **HN compensation: 85/441 captured (~19%).** Salary rarely appears in HN comment first-lines; body-regex catches some. Not broken — just the nature of the source.
- **Seniority correction editor should be a select.** Free-text works but is friction. Bind to a known enum.
- **`parser:reparse-all` should default to single tenant.** Friction at single-tenant scale.
- **Bookmark sort still in PHP collection** (Phase 2 carry-over).
- **Auto-link confidence threshold hard-coded at 0.85** (Phase 2 carry-over).
- **`/discover` "View raw" expander.** Phase 3 ships per-field raw evidence in the correction editor; the card-level expander hinted at in the PRD isn't there.
- **Worktree-based agent dispatch has a real tooling bug.** Worktrees were created off `135811b` (pre-Phase-2) regardless of main HEAD. Agents that did `git merge --ff-only main` recovered; one (12c) shipped against a stale base and had to be rebased post-hoc. Future agent prompts include the fast-forward step explicitly, but the harness should be patched.
- **Spin's PHP container only mounts the main checkout, not agent worktrees.** Several agents reported they couldn't run `spin exec` against their worktree — they ran in main and copied diffs into their branch. Workable but ugly; a worktree-aware compose override would clean this up.
- **`/.mcp.json` still ships a `uidotsh` bearer token in plaintext.** Phase 0/1/2 carry-over. Rotate before any public exposure.

## Verification snapshot at the end of Phase 3

- **PHP test suite:** 564 tests, 2216 assertions, parallel via ParaTest (10 processes), ~6.5 s.
- **Pint:** `{"tool":"pint","result":"passed"}`.
- **Vite build:** clean. `app-*.js` ~389 KB / ~126 KB gzip, `AuthenticatedLayout-*.js` ~150 KB / ~48 KB gzip.
- **Migrations:** 5 new in Phase 3 (parse_corrections, parser_version + widened columns, saved_views, scrape_run_artifacts, parser_confidence_daily, parser_runs). 29 total applied cleanly.
- **End-to-end correction loop tested against live data:**
  - 694 listings ingested via `aggregator:run-all` across HN/Remotive/Himalayas/WWR/RemoteOK.
  - Rolled up via `parser:rollup-confidence` (48 rows, 206 ms).
  - Heat-map at `/admin/parser` matches DB queries.
  - Inline correction on a real WWR listing wrote `ParseCorrection` id=1, updated `parsed` slot + denormalized column.
  - Fixture export landed at `tests/Fixtures/parsers/weworkremotely/weworkremotely-e5b93e7ac833.json` with the corrected value in `expected.seniority`.
  - `parser:reparse-all` (694 rows in 1.1 s, 700/sec) preserved the correction; `parsed_seniority` stayed `senior`, `evidence` stayed `user_correction`, `parser_version` bumped 1 → 2.
  - `parser:reparse-all --force-corrections` reverted the slot to MISSING (parser's honest output), bumped `parser_version` → 3.

## File map for orientation (new in Phase 3)

```
app/
├── Console/Commands/
│   ├── ParserReparseAllCommand.php
│   ├── ParserRollupConfidenceCommand.php
│   ├── ScrapeArtifactsPurgeCommand.php
│   └── ScrapeBackfillCommand.php
├── Http/
│   ├── Controllers/
│   │   ├── Admin/
│   │   │   ├── Parser/                       (Index, ReparseAll, RunRollup)
│   │   │   └── Runs/                         (Index, Show, DownloadArtifact, ScrapeRunListFilters)
│   │   ├── Companies/
│   │   │   └── CompanyListFilters.php        (value-object whitelist)
│   │   ├── Discover/
│   │   │   ├── SaveCorrectionController.php
│   │   │   └── SaveFixtureController.php
│   │   └── SavedViews/                       (Store, Update, Destroy)
│   ├── Middleware/
│   │   └── HandleInertiaRequests.php         (shared savedViews prop)
│   └── Requests/
│       ├── Discover/SaveCorrectionRequest.php
│       └── SavedViews/                       (Store, Update)
├── Models/
│   ├── ParseCorrection.php
│   ├── ParserConfidenceDaily.php
│   ├── ScrapeRunArtifact.php
│   └── SavedView.php
└── Scrapers/Aggregator/
    ├── ApplyCorrectionAction.php
    ├── Artifacts/                            (RecordResponseArtifact, ScrapeArtifactContext)
    ├── HackerNews/Requests/FindHistoricalThreadsRequest.php
    ├── ReparseAction.php
    ├── SaveFixtureAction.php
    └── SourceContextFactory.php

database/migrations/
├── 2026_05_18_000016_create_saved_views_table.php
├── 2026_05_18_171839_create_parse_corrections_table.php
├── 2026_05_18_171840_add_parser_version_and_widen_columns_on_aggregator_listings.php
├── 2026_05_18_172938_create_scrape_run_artifacts_table.php
├── 2026_05_18_182033_create_parser_confidence_daily_table.php
└── 2026_05_18_182220_create_parser_runs_table.php

resources/js/
├── Pages/
│   ├── Admin/
│   │   ├── Parser/Index.tsx
│   │   └── Runs/                             (Index, Show)
│   └── Discover/                             (existing Index.tsx extended with
│                                              EditablePill + CorrectionEditor + chevron menu)
└── components/saved-views/                   (SaveViewButton, SavedViewsNav)

tests/
├── Feature/
│   ├── Admin/                                (Parser/Index + ReparseAll, Runs/Index + Show + DownloadArtifact)
│   ├── Console/                              (ParserRollupConfidence, ParserReparseAll, ScrapeBackfill)
│   ├── Discover/                             (Corrections, SaveFixture, Reparse)
│   ├── Parser/ParsedJsonbRoundTripTest.php
│   ├── SavedViews/                           (Create, Apply, Default, Reorder, DefensiveDegradation)
│   ├── Schema/Phase3SchemaTest.php
│   └── Scrapers/Aggregator/Artifacts/        (ArtifactWrite, ArtifactPurge)
```

## Commit trail (newest first)

```
948f23c  Merge Phase 3 steps 10-11: parser:reparse-all + scrape:backfill
7cedcbf  Phase 3 step 11: scrape:backfill command for HN history + retention-window replay
4cbce4e  Merge Phase 3 step 9: /admin/parser stats dashboard + parser_confidence_daily
65038a6  Phase 3 step 10: parser:reparse-all + parser:reparse --source with resumable chunks
14eae1c  Phase 3 step 9: /admin/parser stats dashboard + daily roll-up
1de3746  Dashboard: default the props array-style props on render
bb83e0c  Fix PHP 8 parenthesization in Admin/Runs ShowController
b71da23  Merge Phase 3 step 8: /admin/runs scrape history viewer with artifact drill-in
786a36f  Phase 3 step 8: /admin/runs scrape history viewer with artifact drill-in
f19285a  Switch to pnpm 11 with 7-day minimum-release-age policy
4f1fb0b  Merge Phase 3 step 12 (partial): /companies advanced filtering + Saved Views
64bb1f4  Phase 3 step 12c: saved view CRUD + Views nav + drag-to-reorder
193820a  Phase 3 step 12b: saved_views schema + model
f572b08  Phase 3 step 12a: advanced filtering on /companies
5c008c5  Merge Phase 3 step 7: raw scraper artifact retention
e098e6f  Merge Phase 3 steps 4-6: inline correction UI + save-as-fixture + re-parse on demand
c0326e7  Phase 3 step 6: re-parse listing on demand with correction preservation
af6e901  Phase 3 step 5: save-as-fixture one-click
f4ade2d  Phase 3 step 7: raw scraper artifact retention
0a10802  Phase 3 step 4: inline correction UI on /discover cards
fe47118  Phase 3 step 3: parsedField() helper + parsed jsonb round-trip test
b72af78  Phase 3 step 2: parse_corrections + parser_version + widen HN-overflowing columns
```

---

**Next phase:** Phase 4 (Signals) per `docs/claude-code-phase-4-kickoff.md`. The parser surface from Phase 2/3 is the substrate; Phase 4 layers hiring volume trends, layoff cross-reference, GitHub activity, blog RSS, funding events, ghost-job detection, company quality score, and the graveyard view. Many of the Phase 3 deferred company columns (stage, size, remote_policy, primary_stack, quality_score) will get their backing data in Phase 4 and unlock the placeholder filters that ship today.
