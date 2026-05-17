# Claude Code Phase 6 Kickoff Prompt — Polish, Notifications, and Sustainability

---

## How to use

1. You've shipped Phases 0–5. The platform has full ingestion, parsing, signals, pipeline, outreach, and calendar.
2. Update `docs/PRD.md` if anything changed.
3. Write a short `docs/PHASE_5_NOTES.md`.
4. Start a **final** Claude Code session at the project root.
5. Paste everything between `=== PROMPT START ===` and `=== PROMPT END ===` as your first message.

Phase 6 isn't glamorous, but it's the difference between a project I use for 3 months and a project I use for 3 years.

---

```
=== PROMPT START ===

# Phase 6: Polish, Notifications, and Long-Term Sustainability

You're closing out v1 of my personal job-hunting platform. Phases 0–5 are shipped. Phase 6 is the polish, automation, and operability layer that lets me keep using this for years without it rotting.

## Read this first

1. The full PRD is at `docs/PRD.md`. Special attention to:
   - Section 7.4 (Notifications) — daily digest, A-tier alerts, source health, weekly summary
   - Section 6.7 (Data Portability and Import) — exports (SQL, JSON, CSV) and bulk operations
   - Section 7.10 (Mobile and Responsive Design)
   - Section 7.11 (Onboarding and First-Run Experience) — refine, not rebuild
   - Section 8.6 (Security) and 8.7 (Operability)
   - Section 8.10 (Accessibility)
   - Section 10, Phase 6 description and ship criterion

2. Read all prior phase notes.

3. **Open a fresh browser to the staging or production deployment and use the platform for 15 minutes.** Note where things feel rough:
   - Slow pages
   - Broken responsive layouts on phone
   - Missing keyboard shortcuts you wanted
   - Empty states that say "no data yet" with no next-step CTA
   - Things you forgot are even there

Bring that list to the planning conversation.

When done, summarize in 6–10 bullets what's already in great shape, what's rough, and what genuinely needs polish before v1 is considered done.

## How we work together

Same pattern. Interactive. PRD as direction. Boring tech. Show reasoning. Stop and confirm.

## What we're building: Phase 6

Per PRD section 10:

> Daily and weekly digest emails. A-tier instant alerts. Source health monitoring with auto-alerting. Settings UI consolidated. Data export (SQL, JSON, CSV). Bulk operations on list views. Backup automation. Accessibility audit pass. Documentation. Final responsive review.
>
> **Ship criterion:** I can hand the platform to future-me six months from now and it still runs without intervention.

### Phase 6 scope, broken down

1. **Daily digest email.** Per PRD section 7.4:
   - Sent at 7am in user's timezone (default CET)
   - Contents: all unseen Events from tracked Companies in last 24h, grouped by company; discovery queue count; A-tier companies stale > 7d; follow-up queue
   - Skip sending if there's nothing new ("no news" is also signal — make this configurable)
   - Postmark or Resend via Laravel Mail

2. **A-tier instant alerts.** Per PRD section 7.4:
   - When a new Role is detected at an A-tier Company, send an email within an hour
   - Throttle: max 1 email per Company per hour (in case of bulk role drops)
   - Respect DND window

3. **Weekly summary email.** Per PRD section 7.4:
   - Sent Sunday morning in user's timezone
   - Stats: new Roles seen, Companies added, Applications sent, follow-ups due
   - Top signals of the week (companies whose quality score changed materially)
   - Plot trend: how many active applications, where they are in the pipeline

4. **Source health monitoring + auto-alerting.** Per PRD section 6.1.6:
   - Per-source: last successful run timestamp, 7-day error rate
   - Email alert when a source fails 3 consecutive scheduled runs OR when parse confidence drops > 20% week-over-week
   - Dashboard tile summary of source health (green/yellow/red)

5. **Settings UI consolidation.** Per PRD section 7.5:
   - Single `/settings` area with sub-pages: Profile, Notifications, Sources, Triage, Account, Data
   - Every config that's been touched manually in `.env` or config files but is naturally runtime-tunable moves here, backed by the `Setting` model from Phase 0
   - DND window, digest send time, auto-dismiss threshold, dismissed-listing retention, per-source enable/disable

6. **Data export.** Per PRD section 6.7.2:
   - `/settings/data` with three export options: SQL dump, JSON normalized, CSV per entity
   - Background job generates the file; output downloadable for 24 hours; queued via the existing scheduled-task infrastructure
   - Includes a manifest with schema version + export timestamp

7. **Bulk operations on list views.** Per PRD section 6.7.3:
   - Selection support on Companies, Roles, AggregatorListings list views
   - Bulk actions: change tier, apply/remove tags, dismiss, mark as bookmarked, mark as closed
   - Confirmable
   - Logged in AuditLog
   - Reversible within 60 seconds via a toast undo

8. **Backup automation.** Per PRD section 8.3:
   - Nightly `pg_dump` to Backblaze B2 (or S3-compatible)
   - 30 days daily / 6 months weekly / monthly indefinite retention tiering
   - Encrypted at rest via B2's server-side encryption
   - Monthly verification job that restores the latest backup to a scratch DB to confirm validity (or at least runs `pg_restore --list` to confirm structural integrity)

9. **Accessibility audit pass.** Per PRD section 8.10:
   - Keyboard navigation: Cmd/Ctrl-K global search, j/k navigation in lists, Esc closes modals, Enter confirms
   - Focus rings visible everywhere
   - Color contrast WCAG AA minimum
   - Form labels and error messages associated programmatically
   - `prefers-reduced-motion` respected
   - `prefers-color-scheme` (dark mode optional, light default)
   - Run an automated audit (axe-core or similar) and address every flagged issue

10. **Mobile/responsive review.** Per PRD section 7.10:
    - Every page works at 375px wide
    - Tap targets ≥ 44px
    - Tables scroll horizontally within their container
    - Navigation collapses to a hamburger drawer below 768px
    - Triage queue, dashboard, company detail are priority — others can be desktop-first if needed

11. **Documentation.** Required for the "future-me 6 months from now" criterion. Several of these docs may already exist as templates from the project setup — your job is to fill them in with phase-by-phase truth, not write from scratch.
    - `README.md` at repo root: confirm it reflects reality. Update the "Status" table to mark all phases shipped. Update commands or stack details if anything drifted.
    - `docs/ARCHITECTURE.md`: confirm it matches what was actually built. Update directory layouts, key models, and conventions if they diverged from the template.
    - `docs/RUNBOOK.md`: this is the most important doc. Walk through every section and confirm the commands actually work on the deployed system. Add any "I learned this the hard way" entries discovered during Phases 0-5.
    - `docs/CHANGELOG.md` (new): per-phase ship summary. Distilled from the `PHASE_N_NOTES.md` files. Concise, dated, what-shipped per phase.
    - `docs/PRD.md`: confirm it still matches reality. Mark any items that were cut or deferred.
    - Inline code comments where the *why* isn't obvious from the *what*.

12. **Operability finishing touches.** Per PRD section 8.7:
    - `/up` health endpoint (already exists from Phase 0?) — return 200 only if DB is reachable AND at least one scraper has run successfully in the last 48h
    - `/health` (auth-required) with detailed JSON: DB connectivity, last successful scrape per source, queue depth, disk free
    - Laravel Telescope installed in production behind auth with 7-day retention
    - Logs structured (JSON) for grep-ability
    - Audit-log viewer at `/admin/audit` (Phase 5 might already have this — verify)

### Phase 6 does NOT include

- The public read-only API (Raycast, CLI). Per PRD section 11.2, not in v1.
- The watchlist-of-people feature. Per PRD section 11.2, deferred indefinitely.
- LLM features. Forever.
- New sources beyond what's already integrated.
- Major UX redesigns. Polish only — keep current shape, improve quality.

If the PRD has Phase 6 items I haven't listed here, flag them.

## How to start

Don't write code yet.

In your first response:

1. **Confirm you've read everything**, including your 15-minute usability walkthrough. Summarize what's polished vs rough.
2. **Validate the scope.** Phase 6 is long — propose a sequence that lets each piece ship on its own. The ship criterion is "I can hand it to future-me," so prioritize the items that most directly support that.
3. **List the questions you need answered.** Examples:
   - Email provider final choice (Postmark vs Resend vs SES) and how to configure sandbox vs production
   - Digest content rules (skip if empty, max items per company, link format)
   - A-tier alert throttling (per company per hour, or per company per day, or by similarity)
   - Bulk operations transactional semantics (one-shot DB transaction vs queued jobs; undo behavior in either case)
   - Backup verification cadence (monthly is the PRD default — confirm)
   - Audit pass scope (only critical paths or every page; acceptance criteria for "done")
   - Mobile review scope (every page or priority pages only; what level of polish constitutes "done")
   - Documentation tone and structure (terse and reference-style vs walkthrough-style)
4. **Propose a Phase 6 task breakdown.** 12 to 20 ordered, shippable steps. This phase has high parallelism — note where things can ship independently.

### Specific things I want done well

- **The digest must skip empty days by default.** Daily "you have nothing new" emails are training me to filter the platform's email into spam.
- **A-tier alerts must not flood me.** A company posting 12 new roles at once = one alert, not 12.
- **Backups must be verifiable.** Untested backups are just performance art. Monthly restore-to-scratch-DB job is non-negotiable.
- **Accessibility audit must be honest.** Don't claim WCAG AA if there are real failures. Run axe-core, list every issue, fix every issue, then claim AA.
- **Documentation must be terse.** I have read every line of code I wrote. The docs are for future-me who has forgotten. Aim for 80% useful in 20% of the words. No marketing prose.
- **The runbook is the most important doc.** "Source X failed, what do I do?" should have a clear answer. "Backup is corrupt, what do I do?" should have a clear answer. "I locked myself out, what do I do?" should have a clear answer.

### Out of scope explicitly (resist the urge)

- Don't redesign anything. Polish only.
- Don't add new features that weren't in Phases 0–5.
- Don't refactor working code unless something is actively broken.
- Don't introduce new dependencies unless one is genuinely needed (e.g., a markdown-to-PDF library for digest emails — discuss before adding).
- Don't build the public API. Phase 7 or later (which we have no plans for).
- Don't add LLM features even for the digest summary. Plain text from real data is fine.

Ready? Read everything, walk through the live platform for 15 minutes, then respond.

=== PROMPT END ===
```

---

## What to watch for in Claude Code's response

- **Did the 15-minute walkthrough actually happen?** If the response is generic, it didn't. Push it to actually use the platform first.
- **Did it ask about email provider and throttling?** A-tier alerts will spam you within a week if not throttled.
- **Did the task list put documentation last?** Bad sign. Documentation should be interleaved, ideally written immediately after each feature ships.
- **Did it flag any "Phase 6 was the dumping ground" items that should have been cut?** Some PRD items, with months of hindsight, may not be worth shipping. Phase 6 is the last chance to cut.

---

## After Phase 6 ships

You're done with v1. The platform should now:

- Run on cron with zero touches needed for weeks at a time
- Send you a digest only when there's something to read
- Survive crashes and disk failures via verified backups
- Have docs that let you debug it 6 months from now without re-reading every file
- Pass an accessibility audit honestly
- Work on your phone for the parts of the workflow you do mobile

What's next is *your* job: use it. The PRD section 4 success metrics are not technical milestones — they're behavioral ones. "80% of senior/staff roles I end up interviewing for came through the platform first." That's a 6–12 month observation, not a phase.

If you find yourself wanting v2 features later (public API, LLM-assisted parsing, multi-user), that's a new PRD. Not Phase 7. Resist the urge to keep extending v1 indefinitely. Ship it, use it, learn from it, then decide what — if anything — to build next.
