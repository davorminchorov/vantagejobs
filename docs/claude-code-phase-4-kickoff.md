# Claude Code Phase 4 Kickoff Prompt — Signals and Intelligence Layer

---

## How to use

1. You've shipped Phases 0–3 (full ingestion, parsing, corrections, run history, advanced filtering).
2. Update `docs/PRD.md` if anything changed.
3. Write a short `docs/PHASE_3_NOTES.md`.
4. Start a **new** Claude Code session at the project root.
5. Paste everything between `=== PROMPT START ===` and `=== PROMPT END ===` as your first message.

This is the phase that turns a tracking tool into a research tool. Take it seriously.

---

```
=== PROMPT START ===

# Phase 4: Signals and Intelligence Layer

You're continuing implementation of my personal job-hunting platform. Phases 0–3 are shipped. We have a working ingestion + parsing + triage pipeline with several months of data. Phase 4 is where the platform starts answering questions like "is this company actually healthy?" not just "is this company hiring?"

## Read this first

1. The full PRD is at `docs/PRD.md`. Read it in full. Special attention to:
   - Section 6.2.8 (Company Graveyard) — including reason tags and the `restored` Event type
   - Section 6.3 (Signals and Analytics) — every subsection
   - Section 10, Phase 4 description and ship criterion
   - Section 11 (Risks and Decisions Log) — the ATS-switch detection logic

2. The sources reference is at `docs/SOURCES.md`. Phase 4 uses **Category 4: Signal Sources** in full — sections 4.1 (layoffs.fyi), 4.2 (TrueUp), 4.3 (GitHub Org Activity), 4.4 (Engineering Blog RSS), 4.5 (Google News for funding), and 4.6 (yc-oss YC Mirror for funding/status). Each section has the exact endpoint, response shape, parsing approach, and gotchas. Use this as ground truth.

3. Read all prior phase notes.

4. **Look at the real data.** Several months in:
   - How many tracked companies have funding info populated? How was it sourced (manual / scrape / yc-oss)?
   - How many have a known GitHub org? Eng blog RSS?
   - What does the distribution of open-role counts look like over time?
   - Are there any companies you'd want to graveyard right now and why? That informs the schema.

Use that empirical view to inform your Phase 4 plan. The PRD is direction; the data is reality.

When done, summarize in 6–10 bullets the current data shape and the most valuable signals to build first.

## How we work together

Same pattern. Interactive. PRD as direction. Boring tech. Show reasoning. Stop and confirm.

## What we're building: Phase 4

Per PRD section 10:

> Hiring volume trend charts (per company and global). Layoff scrape and cross-reference. GitHub org activity poller. Engineering blog RSS monitor. Funding event tracking. Ghost-job and boomerang detection. Company quality score. Company graveyard view with reason tags.
>
> **Ship criterion:** When I view a company, I have enough signal to decide whether to escalate it to A-tier without further research.

### Phase 4 scope, broken down

1. **Daily company snapshots.** A `CompanySnapshot` table that captures, once per day per tracked company: open_role_count, A/B/C tier, status, any other field whose trend matters. This is the substrate for every signal that needs history. Backfill from existing Role data where possible.

2. **Hiring volume trend charts.** Per PRD section 6.3.1:
   - Per-company line chart of open_role_count over time
   - Global view across all A-tier companies
   - shadcn/ui + recharts
   - Steady or growing = healthy; sudden drop = freeze; sustained decline = downsizing signal

3. **Layoffs cross-reference.** Per PRD section 6.3.2:
   - Daily scraper for `layoffs.fyi` (Airtable view, with fallback to Kaggle mirror) and `trueup.io`
   - Normalize company name and fuzzy-match against tracked Companies
   - When matched, create an Event of type `layoff` with severity (headcount, %) if known
   - Contributes negatively to the quality score

4. **GitHub org activity.** Per PRD section 6.3.3:
   - For each tracked Company with a known GitHub org slug, weekly poll:
     - `api.github.com/orgs/{org}` — public_repos, total
     - `api.github.com/orgs/{org}/repos?sort=pushed&per_page=10` — recent push activity
     - Optional: top contributors, new repos created in last 90 days
   - Single composite "eng activity" score per company, with trend arrow
   - Authenticated GitHub requests (token in env) for the higher rate limit

5. **Engineering blog RSS monitor.** Per PRD section 6.3.4:
   - For each tracked Company with a known blog RSS URL, daily poll
   - New posts create Events of type `blog_post` (title, url, published_at)
   - Posts indexed for the existing Cmd-K search (Phase 1)
   - Cadence (posts per 90 days) tracked as a quality signal

6. **Funding event tracking.** Per PRD section 6.3.5:
   - Three sources: yc-oss mirror, manual entry, Google News RSS per tracked company filtered for `raised`, `Series`, `funding`
   - Each funding event becomes an Event of type `funding` and updates the Company's funding fields

7. **Ghost-job detection.** Per PRD section 6.3.6:
   - Auto-flag Roles where any of: posted > 60 days and still active; same `requisition_id` appears in 2+ "new role" Events with re-post gap; company appears in layoffs.fyi within 30 days but still posting; same role on 3+ aggregators but missing from company's own ATS
   - Ghost-flagged roles filtered out of default Roles view, visible in a "Maybe ghosts" tab
   - Threshold values configurable via Settings

8. **Re-posting and boomerang detection.** Per PRD section 6.3.7:
   - Hash `(company, normalized_title, location_bucket)` for every role
   - When a hash reappears after a 14+ day gap, mark as a boomerang and log to role history

9. **Company quality score.** Per PRD section 6.3.8:
   - Composite 1–100 score per Company, recalculated daily
   - Positive contributions: funding recency, eng activity, blog cadence, hiring volume stability, comp transparency
   - Negative: layoffs, hiring volume decline, ghost-job density, no eng blog, no public OSS, repeated red flags
   - Expose the score with a breakdown view ("why this score?") on the company detail page
   - **Not** used to filter anything automatically. Reference only.

10. **Company graveyard.** Per PRD section 6.2.8:
    - Companies move to graveyard via manual demote (status = `graveyard`) or auto (3+ dismissals)
    - Graveyard requires a reason: free-text plus optional tags (`wrong_stack`, `bad_culture_signal`, `comp_too_low`, `geo_restrictive`, `layoffs`, `not_hiring_anymore`, `lost_interest`)
    - `/companies?graveyard=1` view: filterable by reason/tag/date, "Restore to C-tier" action creates a `restored` Event
    - Excluded from default queries, default scrapes, default dashboard counts
    - Historical Roles and Events preserved in place

11. **Compensation aggregation.** Per PRD section 6.3.9:
    - Median, P25, P75 by role family (Senior Backend, Staff Fullstack, etc.) — using already-parsed comp data
    - Same breakdowns by geography (US, EU, Global Remote)
    - Per-company comp ranges where 3+ parsed comp data points exist
    - View at `/insights/comp` or similar

12. **ATS-switch detection.** Per PRD section 11.2:
    - When a Company's ATS slug returns 404 for 7 consecutive scheduled runs, mark `ats_status = needs_redetection`
    - Queue a re-detection job that crawls the careers page and re-runs the ATS auto-detection logic
    - If re-detection fails, surface an alert in the source health panel

### Phase 4 does NOT include

- Pipeline kanban, applications, contacts, calendar — Phase 5.
- Email digest, source health alerting infrastructure — Phase 6.
- Data export, backup automation, documentation pass — Phase 6.
- New aggregators beyond Phase 2's five — Phase 6 if needed.
- LLM features. Never.

## How to start

Don't write code yet.

In your first response:

1. **Confirm you've read everything**, including a peek at the real data. Summarize.
2. **Propose an ordering** for the 12 scope items above. Some have dependencies (snapshots before trend charts; layoffs before quality score; etc.). Lay out the dependency graph.
3. **List the questions you need answered.** Examples:
   - Snapshot table design (one row per company per day vs append-only deltas; how far back to backfill from existing role data)
   - Quality score weights (what's the starting formula; how to make it tunable later)
   - GitHub org slug discovery (manual entry by me vs auto-detect from website meta tags vs combination)
   - Engineering blog RSS discovery (same question — manual vs auto via well-known paths like `/blog/feed.xml`)
   - layoffs.fyi access method (Airtable embed scraping vs Kaggle mirror sync — which is more robust)
   - Ghost detection thresholds and how to surface them (config file vs settings UI)
   - Graveyard reason tags (preset list with optional free-form vs fully free-form)
   - ATS auto-redetection (active crawl vs queued background — what happens to scrape runs in the meantime)
4. **Propose a Phase 4 task breakdown.** 12 to 20 ordered steps, with charts and signals built incrementally rather than in one shot.

### Specific things I want done well

- **Snapshots are the substrate.** Get this right first. Charts, scores, trends — all built on top. Backfill from existing Role data where possible.
- **The quality score must be explainable.** No black box. The breakdown view should show every contributing factor with its weight. If I can't agree with the score, the score is broken.
- **GitHub API rate limits.** Use an auth token from env, batch wisely, cache aggressively. Don't burn the 5,000 req/h budget in one nightly run.
- **layoffs.fyi data is mildly hostile to consume.** It's an Airtable embed, the URL rotates, the format can change. Build defensively. Have a fallback (Kaggle mirror) and an alert path when scrape fails for 3+ days.
- **Ghost-job detection is a feature that must explain itself.** When a role is flagged, the UI shows *why* (which heuristic matched). Otherwise it looks like magic and I won't trust it.
- **Graveyard is read-mostly.** Optimize the default queries to exclude it cheaply (a partial index, a `is_active` boolean column, whatever fits the access pattern).

### Out of scope explicitly (resist the urge)

- Don't refactor the existing scraper, parser, or triage code unless something there is actively blocking Phase 4.
- Don't add the application pipeline or contact CRM. Phase 5.
- Don't build email infrastructure. Phase 6.
- Don't pull in a charting library beyond what shadcn/ui + recharts provide.
- Don't add a "predict whether this company will lay off" or any other LLM-flavored intelligence. Never.

Ready? Read everything, look at the data, then respond.

=== PROMPT END ===
```

---

## What to watch for in Claude Code's response

- **Did it actually propose a dependency order?** Snapshots must come before trend charts. Layoffs must come before quality score. If the task list is flat, push for ordering.
- **Did the quality-score discussion include weights and explainability?** A magic black-box score is useless. If the question of weights doesn't come up, raise it.
- **Did it ask about GitHub auth tokens?** Anonymous GitHub will hit rate limits fast. Token must be in env.
- **Did it flag layoffs.fyi as fragile?** It is. If the response treats it like a clean API, push back.
