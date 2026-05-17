# Claude Code Phase 2 Kickoff Prompt — Aggregators and Triage

---

## How to use

1. You've shipped Phase 1 (ATS scrapers running on cron, Companies UI, Cmd-K search, CSV import).
2. Update `docs/PRD.md` if anything changed during Phase 1.
3. Write a short `docs/PHASE_1_NOTES.md` covering what got built, deviations, and any tech debt you carried.
4. Start a **new** Claude Code session at the project root.
5. Paste everything between `=== PROMPT START ===` and `=== PROMPT END ===` as your first message.

---

```
=== PROMPT START ===

# Phase 2: Aggregators and Triage Queue

You're continuing implementation of my personal job-hunting platform. Phases 0 and 1 are shipped. We're now building the discovery layer: scrapers for aggregator sources, the parser pipeline, and the triage queue that turns noisy aggregator output into actionable signal.

## Read this first

1. The full PRD is at `docs/PRD.md`. Read it in full, paying special attention to:
   - Section 6.1 (Job Ingestion and Parsing) — the parser pipeline is core to this phase
   - Section 6.4 (Discovery and Triage Queue) — the headline feature for Phase 2
   - Section 10, Phase 2 description and ship criterion
   - Section 11 (Risks and Decisions Log) — especially the parser confidence threshold (hard-coded at 0.85) and aggregator listing retention (90 days)

2. The sources reference is at `docs/SOURCES.md`. Read it in full. Phase 2 specifically uses sections 2.1 (HN "Who is Hiring"), 2.2 (Remotive), 2.3 (Himalayas), 2.4 (We Work Remotely), and 2.5 (RemoteOK). Each section documents the exact endpoint, response shape, parsing notes, and gotchas — use it as ground truth, not the PRD's higher-level descriptions.

3. Read `docs/PHASE_0_NOTES.md` and `docs/PHASE_1_NOTES.md` to understand what's in place.

4. Look at the existing scraper foundation. The Phase 1 scraper interface should be extensible for aggregator sources — but you may discover it isn't perfect for the freeform-text nature of HN, RSS, etc. Read the existing scraper classes carefully.

When done, summarize in 5-8 bullets:
- What's already in place that Phase 2 builds on
- Anything in the Phase 1 scraper foundation that needs to be extended (or refactored) for aggregator-style sources
- Anything else worth flagging

## How we work together

Same as before:
- Interactive — ask before significant decisions
- PRD is direction not gospel — push back when warranted
- Boring tech wins
- Show your reasoning briefly
- Stop and confirm at milestones

## What we're building: Phase 2

Per PRD section 10:

> Scrapers for HN "Who is Hiring", Remotive, WWR RSS, Himalayas, RemoteOK. Parser layer with extractors for company, role, location, remote, comp, stack, seniority. Triage queue UI at `/discover` with keep/dismiss/snooze. Auto-linking to tracked companies. Auto-dismiss memory and indicators. Bookmarks (watch later) feature.
>
> **Ship criterion:** I can review and process HN's monthly thread in 15 minutes flat.

### Phase 2 scope, broken down

1. **Aggregator source model.** `AggregatorSource` and `AggregatorListing` tables per PRD section 6.4.1 + 11.2 (90-day retention). Separate from the existing per-company ATS pipeline. Listings are pre-triage; they become Companies + Roles when I keep them.

2. **Scrapers for the five canonical aggregator sources:**
   - **HN "Who is Hiring"** — Algolia HN API (`hn.algolia.com/api/v1/items/{id}`). Find the latest monthly thread, parse top-level comments, dedupe by HN comment ID. Monthly batch run.
   - **Remotive** — `remotive.com/api/remote-jobs?category=software-dev`. Once daily. Respect ToS attribution.
   - **We Work Remotely** — RSS feeds (back-end, full-stack, programming categories). Daily.
   - **Himalayas** — `himalayas.app/jobs/api`. Has `timezoneRestrictions[]` — capture it, it's gold for a CET-based user.
   - **RemoteOK** — `remoteok.com/api`. Daily. Note first JSON element is metadata.

3. **Parser pipeline.** Per PRD section 6.1.4. Pure-function extractors for: company name, role title + discipline + seniority, location + region, remote info (type, timezone, geographic restrictions), compensation (min/max/currency/equity, USD-normalized), stack tags, employment type, visa sponsorship, apply method. Each parsed field returns a value plus a confidence score (0.0–1.0) plus a source flag (`explicit` / `inferred` / `default` / `missing`). Confidence is honest — when guessing, return low.

4. **Stack tag dictionary.** ~200 canonical tags with aliases. Plain TS/PHP file under `dictionaries/`. Match against title and body. Handle the classic false-positive traps (Go, R, C, D requiring word boundaries plus context cues).

5. **Auto-linking.** When a parsed listing's company name matches an existing tracked Company with fuzzy score ≥ 0.85 (hard-coded for now per PRD decision), link it: set `matched_company_id`, status = `linked`, and create an Event of type `new_role` on the matched Company.

6. **Auto-dismiss memory.** `DismissedCompany` table. After 3 manual dismissals of the same normalized company name, set `auto_dismiss = true`. Future listings from that employer arrive in status `auto_dismissed` and bypass the main queue but remain visible under a filter. Dashboard surfaces a small indicator listing active auto-dismiss rules so I don't forget the rule exists.

7. **Volume cap.** If a single aggregate run produces > 200 new listings, only the top 50 by signal (has comp, marked remote, matches preferred stack tags) get status `new`. The rest land as `overflow` for a separate bulk-review queue.

8. **Cross-source dedup.** Within a 14-day window, listings with the same normalized `(company, role_title, location_bucket)` mark the second as a duplicate of the first. UI shows collapsed by default.

9. **Triage queue UI at `/discover`.** Per PRD section 6.4.2 + 6.4.3:
   - Card per listing with parsed fields and confidence indicators
   - Source badge, posted date, timezone badge if relevant
   - "View raw" expander showing original posting
   - Keep / Dismiss / Snooze actions
   - Bulk actions: dismiss multiple, dismiss all from a company, dismiss all matching keyword
   - Filters: source, remote only, has comp, parsed company set, stack tags
   - Default view: status = `new`, newest first

10. **Bookmarks (watch later).** A "bookmark this role" action available on role cards and on triage listings. `/bookmarks` page with sort options. 30-day auto-expiry with a notification before deletion. Distinct from the application pipeline (which is Phase 5).

11. **Aggregator-source admin.** `/discover/sources` to enable / disable sources, see last-run timestamps, and run a single source on demand.

### Phase 2 does NOT include

- The full parser confidence inspector UI with inline corrections — that's Phase 3.
- The "save as fixture" workflow for the parser — Phase 3.
- Scrape run history viewer at `/admin/runs` — Phase 3.
- Saved views, advanced filtering, bulk operations beyond what's needed for triage — Phase 6.
- Signals (layoffs, quality score, ghost detection, etc.) — Phase 4.
- Pipeline kanban, contacts, calendar — Phase 5.
- Email digest — Phase 6.

If the PRD says something belongs in Phase 2 that I haven't listed, flag it.

## How to start

Don't write code yet.

In your first response:

1. **Confirm you've read everything.** Summarize what Phase 1 actually ships and what Phase 2 builds on.
2. **Flag anything in the existing scraper foundation that needs to evolve for Phase 2.** Aggregator-style sources behave differently from per-company ATS sources — they return many companies' jobs per fetch, and the output is freeform text needing a parser pass. The existing interface may need extension.
3. **List the questions you need answered.** Group by topic. Things I'd expect to be asked:
   - Aggregator data model (one table for all listings vs per-source tables, how to store the parsed JSON blob, status enum values)
   - HN parser specifics (which regex patterns to start with, fallback when first-line doesn't conform, fixture format for tests)
   - Parser architecture (extractor interface, how confidence scores are computed and combined into an overall score, how to merge structured-source data with body-text-extracted data)
   - Stack dictionary format (where it lives, how it's edited, normalization rules, false-positive guardrails)
   - Auto-link fuzzy matching (which algorithm — Levenshtein, Jaro-Winkler, normalized substring — and which threshold for the hard-coded 0.85)
   - Triage UI ergonomics (keyboard shortcuts for keep/dismiss/snooze, undo behavior, optimistic updates)
   - Bookmark expiry (background job vs lazy expiry on view, notification mechanism before deletion)
   - Testing the parser (fixture format, where fixtures live, how to assert on the ParsedListing output structure)
4. **Propose a Phase 2 task breakdown.** 12 to 20 ordered, shippable steps. Tests interleaved, not at the end.

### Specific things I want done well

- **Aggregator scrapers must not auto-create Companies.** Parsed listings land in `AggregatorListing` with status `new`. I decide what to keep. Auto-linking to *existing* Companies is fine and creates Events; auto-creating new Companies is not.
- **Parser confidence must be honest.** A 0.4 explicit-match value beats a 0.9 hallucinated one. The UI uses confidence to decide what to surface for review — lying makes the UI useless.
- **No LLM calls anywhere in the parser.** Pure heuristic. Deterministic, testable, free. The PRD is firm on this.
- **The HN parser is where bugs will hide.** Build a fixture suite of real HN postings from the last few monthly threads (sanitize PII if any). Test against them on every parser change.
- **Triage queue must feel fast.** Each action (keep / dismiss / snooze) commits optimistically with a server-side roundtrip in the background. Undo toasts for 5–10 seconds after dismissals.
- **Cross-source dedup must work even when the same company posts to HN, Remotive, AND WWR in the same week.** Normalize company name aggressively for the dedup hash, but preserve the original spelling.
- **The /discover page is the page I'll spend the most time in.** Keyboard navigation matters. J / K to move between cards, Enter or K to keep, X to dismiss, S to snooze.

### Out of scope explicitly (resist the urge)

- Don't build the parser correction UI yet. Phase 3.
- Don't add the parser stats dashboard. Phase 3.
- Don't fetch additional aggregators beyond the five listed. Phase 6 if needed.
- Don't auto-discover ATS slugs for newly-kept listings. That's a "kept" workflow improvement for Phase 6.
- Don't change the existing Greenhouse / Lever / Ashby scrapers unless something they do is actively blocking the new aggregator work.

Ready? Read everything, then respond.

=== PROMPT END ===
```

---

## What to watch for in Claude Code's response

- **Did it ask about parser architecture, not just file structure?** The parser pipeline is the hardest engineering work in this phase. If the questions stay shallow, push for depth.
- **Did it propose tests early in the task list?** HN parser fixtures need to exist before the parser, not after.
- **Did it flag the cross-source dedup as harder than it looks?** Normalizing company names across sources (Acme Corp vs. Acme, Inc. vs. ACME) is a real edge-case minefield.
- **Did it ask about the existing scraper interface?** Phase 1's scraper assumed one-company-per-fetch. HN is one-fetch-yields-300-companies. Real refactor question.
