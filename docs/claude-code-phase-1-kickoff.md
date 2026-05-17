# Claude Code Phase 1 Kickoff Prompt — ATS Pipeline

---

## How to use

1. You've shipped Phase 0 (login works, dashboard skeleton, schema in place).
2. Update `docs/PRD.md` if anything changed during Phase 0 implementation.
3. Write a short `docs/PHASE_0_NOTES.md` summarizing what got built, key decisions, and any deviations from the PRD. (5-15 bullets is enough. Or commit messages will do in a pinch.)
4. Start a **new** Claude Code session at the project root.
5. Paste everything between `=== PROMPT START ===` and `=== PROMPT END ===` as your first message.

A fresh session keeps context tight. Don't carry one Claude Code session across all 7 phases.

---

```
=== PROMPT START ===

# Phase 1: ATS Pipeline

You're continuing implementation of my personal job-hunting platform. Phase 0 is shipped. We're now building the first real value-delivering layer: ATS scrapers for Greenhouse, Lever, and Ashby, plus the Companies UI.

## Read this first

1. The full PRD is at `docs/PRD.md`. Read it in full, paying special attention to:
   - Section 6.1 (Job Ingestion and Parsing)
   - Section 6.2 (Company Tracking and Dossiers)
   - Section 6.5 (Search, Filtering, Saved Views)
   - Section 6.6 (Tags, Bookmarks, Annotations)
   - Section 6.7 (Data Portability and Import)
   - Section 9 (Technical Architecture)
   - Section 10, specifically the Phase 1 description and ship criterion
   - Section 11 (Risks and Decisions Log)

2. The sources reference is at `docs/SOURCES.md`. Read it in full. This is the canonical engineering reference for every source's exact endpoint, response shape, rate limits, parsing notes, and gotchas. Phase 1 specifically uses sections 1.1 (Greenhouse), 1.2 (Lever), 1.3 (Ashby), and the cross-cutting guidance at the top. The Scraper Interface Contract in Appendix A is the contract to design against.

3. The Phase 0 notes are at `docs/PHASE_0_NOTES.md`. Read them to understand what was actually built versus the PRD plan, and whether there were any deviations or technical-debt decisions you need to inherit.

4. Look at the codebase as it exists. Read the schema, the existing models, the route file, the Inertia React pages, the auth setup. Understand what's already there before proposing to add anything.

When done, summarize in 5-8 bullets:
- What Phase 0 actually shipped (vs PRD)
- Anything in the existing code you'd flag for cleanup before Phase 1 (don't fix it yet, just note it)
- The most important constraints Phase 1 has to respect

## How we work together

Same as Phase 0. To recap:

- **Interactive, not autonomous.** Stop and ask before any non-trivial decision.
- **The PRD is direction, not gospel.** Push back when you spot something wrong.
- **Prefer fewer, better dependencies.** Each new package is a long-term tax.
- **Boring tech wins.** This is a multi-year personal tool.
- **Show your work.** Two sentences of reasoning per non-obvious choice.
- **Stop and confirm at milestones.** Don't barrel through into Phase 2.

## What we're building: Phase 1

Per PRD section 10:

> Scrapers for Greenhouse, Lever, Ashby. Concurrent fetching via Guzzle Pool. Diff and dedup logic. Events created for new roles. Companies UI (list, create, edit, detail) with basic filters. Working scrape command runnable from cron. CSV import wizard for spreadsheet migration. Tags model on Companies. Global search palette (Cmd/Ctrl-K) for Companies and Roles.
>
> **Ship criterion:** I can import my spreadsheet, add new companies, watch them get scraped, see new roles appear, and find anything with Cmd-K.

### Phase 1 scope, broken down

In rough priority order:

1. **Scraper foundation** — common scraper interface, source registry, run orchestration, concurrent HTTP via Guzzle Pool with per-source concurrency caps, error isolation per company, polite retry/backoff, conditional GET (ETag / Last-Modified) where supported.
2. **Greenhouse scraper** — implements the interface; pulls `boards-api.greenhouse.io/v1/boards/{token}/jobs?content=true`; handles the unstructured `location.name` and free-form `metadata[]`.
3. **Lever scraper** — `api.lever.co/v0/postings/{site}?mode=json`; capture `workplaceType` and structured `salaryRange` when present.
4. **Ashby scraper** — `api.ashbyhq.com/posting-api/job-board/{name}?includeCompensation=true`; pull structured compensation tiers.
5. **Diff and dedup** — for each company on each run: detect new roles (insert + Event), detect closed roles (mark `is_open=false`), update `last_seen_at` on still-open roles, update `last_checked_at` on Company. Idempotent re-runs.
6. **Scrape command** — `php artisan scrape:all` with per-source filters; structured output suitable for cron logs; per-run record (start, end, fetched, new, errors).
7. **Companies UI** — list view with basic filters (tier, status, ATS type, last_checked range), create/edit forms, detail page with roles tab and timeline tab. Inertia + React + shadcn/ui.
8. **Tags model** — many-to-many on Companies (and structurally also on Roles, even if the Roles UI doesn't expose it yet in Phase 1). Tag autocomplete on add.
9. **CSV import wizard** — `/import` route with column auto-detect, preview, idempotent run keyed on website domain. Per section 6.7.1 of the PRD.
10. **Global search (Cmd/Ctrl-K)** — Postgres full-text search across Companies and Roles. shadcn `Command` component. Keyboard-driven. Result grouping by entity type.

### Phase 1 does NOT include

- Aggregator sources (HN, Remotive, WWR, Himalayas, RemoteOK) — those are Phase 2.
- The parser layer with confidence scoring and corrections — that's Phase 3.
- The triage queue UI — also Phase 2.
- Signals: layoffs scrape, GitHub activity, eng-blog RSS, quality score, ghost detection — all Phase 4.
- Application pipeline, outreach, calendar — Phase 5.
- Email digests, source health alerts, advanced filtering, saved views, bulk operations — Phase 6 (saved views could slip earlier if cheap).
- Workable / SmartRecruiters / Personio / Recruitee / Teamtailor / 4dayweek / Adzuna scrapers — Phase 2 expansion.

If the PRD says something belongs in Phase 1 that I haven't listed here, flag it before we start.

## How to start

Don't write code yet. Don't add migrations yet. Don't install packages yet.

In your first response:

1. **Confirm you've read the PRD and Phase 0 notes.** Summarize what's already in place (5-8 bullets).
2. **Flag anything in the existing code that needs fixing first.** Technical debt, naming inconsistencies, missing indexes, anything you'd want to address before piling Phase 1 on top. Categorize as "must fix before Phase 1," "should fix during Phase 1," or "can defer."
3. **List the questions you need answered before scaffolding Phase 1.** Group by topic. Examples of what I'd expect to be asked:
   - Scraper architecture (one class per source vs. shared adapter pattern, where they live, how they're registered, how to test them)
   - Schema details (Role uniqueness — external_id + source — vs. — external_id + company —, status enums on Role, snapshot retention for trend charts)
   - Concurrency policy (per-source defaults, global ceiling, how to surface 429 backoff)
   - HTTP client configuration (timeout per source, User-Agent string, whether to share a Guzzle client across pools)
   - Tag implementation (single tags table, polymorphic vs. dedicated pivots, case-handling, normalization)
   - CSV import (file storage, in-flight vs. queued, validation rules, where the wizard state lives)
   - Search (Postgres FTS configuration, tsvector columns, refresh triggers vs. application-side updates)
   - Test strategy (real HTTP recordings for scrapers via PSR-18 mock, fixture-based diff tests, feature tests for the UI)
   - Frontend conventions for new pages (page component organization, shared form components, URL state encoding for filters)
   - Anything else genuinely material
4. **Propose a Phase 1 task breakdown.** 10 to 20 concrete steps, ordered. Each shippable on its own. Include test coverage for each step explicitly — Phase 1 is where the platform starts having behavior worth regressing on.

Once I answer your questions and approve the task list, you'll execute one step at a time, stopping after each step for me to review.

### Specific things I want done well

- **The scraper interface must be source-agnostic.** Adding a new source in Phase 2 should be a single class change. Don't bake Greenhouse-specific behavior into the orchestration layer.
- **Concurrent fetching must respect per-source rate limits.** A naive `Http::pool()` of 60 requests across 60 different hosts is fine; 60 requests against the same host is not. Per-source concurrency settings.
- **Diff logic must be idempotent.** Running the scraper twice in a row should produce zero new Events the second time. Test this explicitly.
- **Every scraper run produces a record I can inspect later.** Even if the admin/runs UI isn't until Phase 3, the data should be captured from day one.
- **CSV import must be re-runnable.** Same CSV imported twice = no duplicates. Idempotent on website domain match.
- **Cmd-K must feel fast.** Under 100ms server response to a query. Use Postgres FTS, not LIKE queries.

### Out of scope explicitly (resist the urge)

- Don't build the parser layer with confidence scoring. Phase 3 problem.
- Don't add aggregator sources. Phase 2 problem.
- Don't add the company graveyard view. Phase 4 problem.
- Don't build the signals score. Phase 4.
- Don't ship a beautiful empty state or onboarding flow. Phase 6 polish.
- Don't add LLM-based anything, ever.

Ready? Read the PRD, read the Phase 0 notes, look at the existing codebase, and respond.

=== PROMPT END ===
```

---

## What to do after Claude Code responds

Same pattern as Phase 0. You'll get summary + pushback + questions + task list. Answer the questions in one batch, edit the task list, approve, then execute step by step.

A few things specifically worth checking in Claude Code's response for Phase 1:

- **Did it flag any Phase 0 technical debt honestly?** If everything looks rosy, push: "Anything you'd refactor before piling on Phase 1?"
- **Are the scraper questions about the right things?** Look for questions about idempotency, concurrency per host, ETags, and error isolation. Lack of those questions is a red flag.
- **Is the task list properly ordered?** Schema and scraper foundation should come before any one specific scraper. Diff logic should come before the UI. Tests should be interleaved, not bolted on at the end.
