# Claude Code Phase 3 Kickoff Prompt — Parser Polish and Corrections

---

## How to use

1. You've shipped Phases 0–2 (login, ATS scrapers, aggregators, parser pipeline, triage queue).
2. Update `docs/PRD.md` if anything changed during Phase 2.
3. Write a short `docs/PHASE_2_NOTES.md` covering what got built, deviations, real-world parser accuracy observations.
4. Start a **new** Claude Code session at the project root.
5. Paste everything between `=== PROMPT START ===` and `=== PROMPT END ===` as your first message.

By this point you should have a few weeks of real triage data in the DB. Phase 3 is where you make that data work for you instead of against you.

---

```
=== PROMPT START ===

# Phase 3: Parser Polish, Corrections, and Run History

You're continuing implementation of my personal job-hunting platform. Phases 0–2 are shipped. Phase 2 gave us a working parser with confidence scoring; Phase 3 makes the parser improvable, debuggable, and observable.

## Read this first

1. The full PRD is at `docs/PRD.md`. Read it in full. Special attention to:
   - Section 6.1.4 (Parser Layer) — what's already there
   - Section 6.1.5 (Parse Correction Loop) — the headline feature
   - Section 6.1.6 (Source Health Monitoring)
   - Section 6.1.7 (Backfill)
   - Section 6.5 (Search, Filtering, Saved Views) — advanced filtering and Saved Views land here
   - Section 7.8 (Observability and Debugging) — the run history viewer
   - Section 10, Phase 3 description and ship criterion

2. Read `docs/PHASE_0_NOTES.md`, `docs/PHASE_1_NOTES.md`, `docs/PHASE_2_NOTES.md`.

3. **Look at the actual parser performance in production data.** Query the DB:
   - Distribution of per-field confidence scores across the last 30 days
   - Top 10 sources × fields with the lowest median confidence
   - Listings I've manually corrected if any
   - Anything that smells off

Use that empirical view to inform your Phase 3 plan. The PRD is direction; the data is reality.

When done, summarize in 6–10 bullets:
- What Phase 2 actually ships (parser scope, confidence behavior, triage UX)
- What the parser is currently bad at, based on observed data
- What Phase 3 needs to prioritize given those weaknesses

## How we work together

Same pattern. Interactive. PRD as direction. Boring tech. Show reasoning. Stop and confirm.

## What we're building: Phase 3

Per PRD section 10:

> Parse confidence scoring. Inline correction UI. ParseCorrection table. "Save as test fixture" workflow. Parser stats dashboard at `/admin/parser`. Raw scraper artifact retention (7 days). Scrape run history viewer at `/admin/runs`. Advanced filtering + Saved Views.
>
> **Ship criterion:** When I correct a bad parse, the platform learns from it; I can build and save complex filter combinations.

### Phase 3 scope, broken down

1. **`ParseCorrection` table.** Per PRD section 6.1 schema. Columns: id, listing_id, field, old_value, new_value, created_at. Append-only. Linked to the listing.

2. **Inline correction UI in the triage queue.** Per PRD section 6.4.2 + 7.8:
   - On each parsed field on a listing card, a "correct this" affordance
   - Inline editor showing current value, confidence, and rawMatch snippet
   - Save writes a `ParseCorrection` row, updates the listing's parsed values, and optionally offers "save as test fixture"
   - Re-parsing the listing later preserves the corrected field unless explicitly forced

3. **"Save as test fixture" workflow.** Per PRD section 6.1.5. When I correct a parse:
   - One-click button: "Save this listing as a test fixture with my corrections as expected output"
   - Fixture file is written to `tests/fixtures/parser/{source}-{shortid}.json` (or wherever your test structure lives)
   - Includes the raw input and the expected ParsedListing structure
   - Optionally regenerates snapshot files so the new fixture is enforced going forward

4. **Parser stats dashboard at `/admin/parser`.** Per PRD section 6.1.6:
   - Per-source, per-field median confidence over last 30 days
   - Top 10 lowest-confidence fields (where parsing is weakest)
   - Count of corrections per field per source (where I'm overriding the parser most)
   - List of recent corrections with links back to listings
   - "Re-parse all" button (with confirmation), preserving existing corrections

5. **Raw scraper artifact retention.** Per PRD section 11.2:
   - On every scrape run, store the raw HTTP response per source/company
   - Retention: 7 days, configurable
   - Storage: a `ScrapeRunArtifact` table or compressed blobs on disk under `storage/scrape-artifacts/{yyyy-mm-dd}/`
   - Decision needed: DB blob vs filesystem — discuss before implementing
   - Background job purges artifacts older than retention

6. **Scrape run history viewer at `/admin/runs`.** Per PRD section 7.8:
   - List of every scheduled run with start/end time, duration, source breakdown
   - Drill-in per run: per-source error log, per-company fetch status, raw HTTP response viewer (for the 7-day retention window)
   - Filterable by date range and source

7. **Re-parse command.** Per PRD section 6.1.6:
   - `php artisan parser:reparse-all` reads every AggregatorListing, runs the current parser, writes new parsed output, increments `parser_version`
   - Preserves user corrections unless `--force-corrections` is passed
   - Per-source: `php artisan parser:reparse --source=hn_hiring`

8. **Backfill command.** Per PRD section 6.1.7:
   - `php artisan scrape:backfill --source={source} --days={N}` re-fetches sources back N days where the source's API permits
   - For HN: re-pull last N monthly threads
   - For Remotive/RemoteOK/WWR: limited (those are realtime); essentially a "re-process" using cached artifacts if still in the 7-day window

9. **Advanced filtering on Companies and Roles list views.** Per PRD section 6.5.2:
   - Faceted filters: every dimension listed in the PRD (tier, stage, size, remote policy, domain, primary stack, ATS type, has-open-roles, last_checked_at range, quality score range — though quality score is Phase 4)
   - URL-state-backed so they're bookmarkable and shareable
   - Quick filter chips at the top for common combos

10. **Saved Views.** Per PRD section 6.5.3:
    - Any filter combination can be named and saved
    - Saved Views appear in left navigation under a "Views" group
    - Drag-to-reorder
    - Default view setting

### Phase 3 does NOT include

- Hiring volume trend charts, layoff scrape, GitHub activity, blog RSS, funding tracking, quality score, ghost detection, graveyard — all Phase 4.
- Pipeline kanban, contacts, calendar, outreach — Phase 5.
- Email digest, source health alerting, bulk operations, data export, accessibility audit — Phase 6.
- New parser features beyond what Phase 2 shipped — improve, don't expand. (Exception: if data shows the parser is missing a critical field, flag it for discussion.)
- LLM-based parsing. Never.

## How to start

Don't write code yet.

In your first response:

1. **Confirm you've read everything**, including the actual parser-performance data from production. Summarize.
2. **List the parser weaknesses you observed** in the real data. Rank by impact.
3. **List the questions you need answered.** Examples I'd expect:
   - Correction-preservation strategy (column-level merge on re-parse vs full override-and-replay)
   - Fixture file format and location (JSON? PHP? Where in the test tree?)
   - Stats dashboard query strategy (precomputed roll-up tables vs on-the-fly aggregation; cache strategy)
   - Artifact storage decision (DB blob vs filesystem) — pros and cons for our scale
   - Saved Views schema (per-user even though single-user, ordering field, default-view flag)
   - Re-parse command behavior on listings that have been kept/linked/dismissed (skip closed? re-parse anyway?)
   - URL-state encoding for filters (query string vs. JSON-in-base64; URL length implications)
4. **Propose a Phase 3 task breakdown.** 10 to 18 ordered, shippable steps with tests interleaved.

### Specific things I want done well

- **The correction loop is the headline.** Friction here kills the whole feedback flywheel. Saving a correction should be 2 clicks. Saving as a fixture should be 1 more click. If it takes 5 clicks, you've built the wrong thing.
- **Stats must be queryable in real time.** The `/admin/parser` page should load under 200ms. Materialized views or daily roll-up tables are fine. Per-query aggregation across millions of rows is not.
- **Artifact retention must auto-purge.** No manual cleanup. A scheduled job runs daily.
- **The re-parse command must be safe.** Re-running on millions of rows shouldn't lock the DB, shouldn't wipe corrections, and should be resumable if interrupted.
- **Saved Views must not break when a filter dimension changes.** Defensive parsing: if a saved view references a tag or source that no longer exists, gracefully degrade rather than 500.
- **The runs history view must reveal failures clearly.** When a source is down, I should see it within one click of `/admin/runs`.

### Out of scope explicitly (resist the urge)

- Don't add new sources. We have the five canonical aggregators plus the three ATS scrapers. Phase 6 if more are needed.
- Don't build the signals layer. Phase 4.
- Don't add bulk operations across multiple entities yet. Phase 6.
- Don't redesign the triage queue UI. Tweak only if you must.
- Don't add monitoring/alerting infrastructure. Phase 6.
- Don't change the parser's core logic unless data shows a critical bug. Improve via corrections, not redesign.

Ready? Read everything, query the parser data, then respond.

=== PROMPT END ===
```

---

## What to watch for in Claude Code's response

- **Did it actually look at the production parser data?** If the summary is generic, push it to query the DB before answering.
- **Did the questions cover correction-merge strategy?** The non-trivial design decision in this phase is "what does re-parse do when there are corrections?" — make sure that's surfaced.
- **Is the fixture format pragmatic?** JSON is the obvious default. PHP arrays are tempting but worse for git diffs.
- **Did the task list put the correction UI before the stats dashboard?** Stats are nice; corrections are the value. Order matters.
