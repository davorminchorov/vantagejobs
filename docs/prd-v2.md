# PRD: Personal Job-Hunting Platform

**Status:** Draft v3 (ready for implementation; scaffold underway)
**Author:** You
**Date:** May 2026
**Stack:** Laravel 13, PHP 8.5, FrankenPHP, PostgreSQL 18, Inertia + React, Tailwind, Laravel Cloud (EU)
**Local dev:** Spin (Docker Compose) wrapping `serversideup/php` images, `vantagejobs.test`
**Scope:** Single active tenant in v1 (just me), but the schema and code paths are tenant-aware from day one. No public registration, no sharing UI, no team accounts. The intent is to pay the small upfront cost of tenant scoping so future migration to "me plus one or two friends" is a seeder change, not a rewrite.

---

## 1. Overview

A personal platform that ingests job listings from multiple ATS APIs and aggregators, parses them into a normalized schema, surfaces signals about the companies behind them, and gives me a single place to manage my watch list, dossiers, and triage queue. Built for me, not as a SaaS. Optimized for senior and staff remote roles, with an EU/CET geographic bias.

The platform replaces a sprawl of spreadsheets, browser tabs, and ad-hoc note-taking with one tool that runs on a schedule, surfaces what changed, and lets me decide what to act on.

## 2. Problem Statement

Job hunting at a senior level is a research problem more than an application problem. The bottleneck is not "how do I send more applications" but "how do I find the right 30 companies, watch them passively over months, and act when the right role appears at the right one." Existing tools either:

1. Optimize for volume (auto-apply, mass-resume tools) which is the opposite of what works at senior level.
2. Target recruiters or talent acquisition teams, not candidates.
3. Are SaaS products with churn-driven economics and short feature horizons.

The platform fixes this by being a passive intelligence tool for a single user, watching a curated company list and feeding me signal, not noise.

## 3. Goals and Non-Goals

### Goals
- Replace my current spreadsheet plus dossier folder with one cohesive system.
- Watch 200 to 500 companies passively, polling their ATSes every 6 to 24 hours.
- Ingest from 10+ sources without a separate workflow per source.
- Surface meaningful change (new role, funding, layoff, hire, blog post) within 24 hours of detection.
- Parse free-form job postings into a structured, filterable schema with confidence scores.
- Give me a triage queue for discovery sources so I never lose a good lead in noise.
- Be cheap to run (less than $15/month all-in) and trivial to back up.

### Non-Goals (Out of Scope for v1)
- User-facing multi-tenancy: no sharing UI, no team accounts, no cross-tenant features, no social surfaces. (The schema is tenant-aware so a future expansion is cheap; the experience is single-user.)
- Public sign-ups, role-based permissions, or user management UI.
- Any LLM or AI features (parsing, matching, suggestions, cover letters).
- Auto-apply, resume generation, or interview prep tooling.
- Browser extension or mobile app.
- LinkedIn, Indeed, or Glassdoor scraping (legal risk plus low signal).
- Public sharing or "discover companies others are watching."
- A separate iOS/Android client.

## 4. Success Metrics

Personal-tool metrics, measured against my own behavior:

- I check the dashboard daily without it feeling like a chore.
- 80% of senior/staff roles I end up interviewing for came through the platform first, not from a friend's text message or LinkedIn.
- Average time from "company posts new role" to "I see it" is under 24 hours.
- Triage queue stays under 50 unread items at all times (signal that auto-dismiss and dedup are working).
- Total scrape run completes in under 90 seconds for the full company list.
- I never have to ask "is this company still hiring" because the platform already knows.

## 5. Users

Just me. One active user, one active tenant. No personas section needed.

Relevant context for design decisions: senior/staff backend and fullstack engineer, based in Skopje (CET/CEST), targeting global remote, EU-friendly companies preferred, comfortable with technical complexity, will iterate on the tool over months.

The tenant model exists in the schema so that "let a trusted friend run their own search inside the same instance" is a future seeder change, not a rewrite. No second tenant is created or exposed in v1, and no UI surface in v1 references tenants at all.

---

## 6. Core Features

The four feature areas below are tier-one priorities. Application pipeline and outreach are in section 7 as supporting features.

### 6.1 Job Ingestion and Parsing

The data foundation. Everything else depends on this being reliable, fast, and honest about uncertainty.

#### 6.1.1 Multi-Source Scraping

Sources to integrate, organized by tier from the research:

**Phase 1 (must-have, integrate first):**
- Greenhouse Job Board API
- Lever Postings API
- Ashby Posting API (with `includeCompensation=true`)
- Personio XML feeds (EU bias)
- Hacker News "Who is Hiring" via Algolia HN API
- Himalayas API
- Remotive API
- We Work Remotely RSS
- RemoteOK API
- YC company directory (yc-oss mirror, for company seeding)

**Phase 2 (after foundation is stable):**
- Workable widget API
- SmartRecruiters Posting API
- Recruitee per-company API
- Teamtailor XML feeds
- 4dayweek.io RSS
- Working Nomads JSON
- Adzuna API (EU geo-discovery)

Each source is implemented as a self-contained scraper class with a common interface, so adding new sources later is a one-class change.

#### 6.1.2 Concurrent Fetching

Scrapers use Guzzle Pool with per-source concurrency limits:
- Greenhouse / Lever / Ashby: concurrency 10
- Personio / Workable / SmartRecruiters: concurrency 5
- Remotive: concurrency 1 (strict rate limit)
- HN Algolia: serial, monthly batch

Per-host rate limiting respects each source's documented ToS. Per-run state tracks 429s and backs off the whole source pool when triggered.

#### 6.1.3 Diff and Deduplication

On each run, per company:
1. Fetch current open roles.
2. Compare to existing `Role` rows for that company by stable external ID.
3. New roles (not in DB) get inserted plus an `Event` of type `new_role`.
4. Roles in DB but missing from fetch get marked `is_open = false`.
5. Roles seen again update `last_seen_at`.
6. Update `last_checked_at` on Company.

Cross-source dedup: when the same role appears on the company's ATS AND on Remotive/HN/etc, the ATS version is canonical and others are linked as duplicates.

#### 6.1.4 Parser Layer

Postings get parsed through a pipeline of pure-function extractors:
- Company name (normalized, with original preserved)
- Role title, normalized discipline, seniority
- Location (cities, countries, region)
- Remote info (type, timezone requirement, geographic restrictions)
- Compensation (min, max, currency, equity, hourly vs annual, normalized to USD)
- Stack tags (matched against a dictionary of ~200 canonical tags with aliases)
- Employment type
- Visa sponsorship signal
- Apply method

Every parsed field carries a confidence score (0.0 to 1.0) and a source flag (`explicit`, `inferred`, `default`, `missing`). The UI uses confidence to decide what to surface for manual review.

#### 6.1.5 Parse Correction Loop

When I correct a parse in the UI:
1. The correction is saved as a `ParseCorrection` row.
2. The listing is offered as a new test fixture with one click.
3. Corrections are preserved on re-parse runs (never overwritten unless explicitly forced).

This is the mechanism by which the parser improves over time without me writing tests manually.

#### 6.1.6 Source Health Monitoring

Per-source dashboard panel tracks:
- Last successful run timestamp
- 7-day error rate
- Parse confidence trends per field
- Volume change vs previous period

Alert (email) when a source fails 3 consecutive runs or when parse confidence drops more than 20% week-over-week.

#### 6.1.7 Backfill

A `scrape:backfill` artisan command that re-fetches all sources back N days. Used after adding a new field to the parser, or after fixing a scraper bug.

### 6.2 Company Tracking and Dossiers

The heart of the platform. The company database is the canonical reference; everything else hangs off it.

#### 6.2.1 Company Database

Each company has:
- Identity: name, website, careers URL, ATS type and slug
- Classification: tier (A/B/C), stage, size, remote policy, timezone requirement, domain, primary stack
- Health: last funding round and date, recent layoffs (Y/N + date), Glassdoor rating, eng blog active (Y/N), hiring activity status
- Personal: why I want to work here, status (researching/watching/applied/in process/rejected/offer/passed), notes
- Operational: `last_checked_at`, created/updated timestamps

#### 6.2.2 Watch List Management

Three tiers with different cadence and budget:
- **A-tier (10 to 15 companies):** Dream companies. Polled every 6 hours. Full dossiers maintained. Email alert on any new role.
- **B-tier (30 to 50 companies):** Strong fit. Polled every 12 hours. Light tracking.
- **C-tier (50 to 100+ companies):** Acceptable fallback. Polled every 24 hours. Minimal tracking.

Tier can be changed at any time; cadence updates automatically.

#### 6.2.3 ATS Auto-Detection

When I add a new company by URL, the platform attempts to detect the ATS automatically:
- Crawl the careers page.
- Look for known patterns: `boards.greenhouse.io/{slug}`, `jobs.lever.co/{slug}`, `jobs.ashbyhq.com/{slug}`, `{slug}.workable.com`, `{slug}.recruitee.com`, `jobs.personio.de/{slug}`, etc.
- If detected, save ATS type and slug; otherwise prompt me to set manually.

#### 6.2.4 Bulk Import

Seed the watch list from curated sources:
- YC `yc-oss.github.io/api/companies/hiring.json` filtered by batch and industry
- GitLab All-Remote list
- Remotive Companies directory
- Manual CSV upload (name, website, optional tier)

Bulk import is idempotent (dedup by website domain) and adds at C-tier by default.

#### 6.2.5 Dossier Editor

Per A-tier company, a markdown dossier with structured sections (basics, business model, funding, eng culture, why interested, concerns, contacts, talking points, questions to ask). Pre-filled template on creation. Side-by-side edit and preview. Saved on blur, no save button.

#### 6.2.6 Timeline View

Per company, a chronological feed of every Event ever recorded: new roles, role closures, funding, layoffs, blog posts, hires, departures, my own notes. The timeline is the audit log of "what happened at this company."

#### 6.2.7 Contacts

Light contact CRM per company:
- Name, role, LinkedIn URL, email, last touch date, notes
- Used by the outreach feature in section 7.

#### 6.2.8 Company Graveyard

A view of companies I once tracked but have since de-prioritized. Useful for self-reflection ("did I make the right call passing on X?") and for avoiding re-discovering and re-evaluating the same companies later.

A company moves to the graveyard when I:
- Manually demote it to `status = 'graveyard'` via the company detail page, or
- Auto-dismiss its parent employer 3+ times via the triage queue (configurable threshold).

Graveyard requires a reason (free-text plus optional tags: `wrong_stack`, `bad_culture_signal`, `comp_too_low`, `geo_restrictive`, `layoffs`, `not_hiring_anymore`, `lost_interest`).

Graveyard view (`/companies?graveyard=1`):
- Lists all graveyarded companies with reason, tag, date demoted
- Filterable by reason tag and date range
- "Restore to C-tier" action with one click (creates an Event of type `restored` for the audit trail)
- Read-only by default; clicking into a company shows its full historical Events and Roles

Excluded from default queries: not polled, not counted in dashboard stats, not surfaced in the discovery auto-link pass. They exist only when explicitly requested.

### 6.3 Signals and Analytics

What makes this a research tool, not just a tracker.

#### 6.3.1 Hiring Volume Trends

Per company, a chart of open-role count over time, computed from the daily snapshots that the scraper takes. This is the highest-signal quality indicator the platform has access to:
- Steady or growing = healthy
- Sudden drop = hiring freeze (investigate)
- Sustained decline over 90 days = downsizing signal

#### 6.3.2 Layoff Cross-Reference

Daily scrape of layoffs.fyi (Airtable) and TrueUp. Matches by normalized company name. When a tracked company appears, creates an Event of type `layoff` with severity (headcount affected, percentage if known). Affects company quality score.

#### 6.3.3 GitHub Org Activity

For tracked companies with a known GitHub org:
- Public repo count and trend
- Recent commit activity across top repos
- Contributor growth
- New repo creation rate

Polled weekly. Computed as a single "eng activity" score with trend arrow.

#### 6.3.4 Engineering Blog Monitoring

For tracked companies with a known eng blog RSS:
- New posts trigger Events of type `blog_post`
- Posts indexed for search across all tracked companies
- Cadence tracked (posts per 90 days) as quality signal

#### 6.3.5 Funding Events

Detected via three sources:
- YC mirror (for YC companies)
- Manual entry (paste a TechCrunch link, fill the form)
- Google News RSS for each tracked company (filtered for keywords: raised, Series, funding)

Each funding event becomes an Event and updates the Company's funding fields.

#### 6.3.6 Ghost Job Detection

A Role is flagged as a likely ghost when any of these conditions trigger:
- Posted more than 60 days ago and still active.
- Same requisition ID appears in 2+ "new role" Events with a re-post gap.
- The company appears in layoffs.fyi within 30 days but is still posting.
- Same role appears on 3+ aggregators but is missing from the company's own ATS.

Ghost-flagged roles are filtered out of the default dashboard view but visible in a separate "Maybe ghosts" tab.

#### 6.3.7 Re-Posting and Boomerang Detection

Hash `(company, normalized_title, location_bucket)` for every role. When the same hash reappears after a gap of 14+ days, mark as a boomerang and log to the role's history. Useful for spotting roles that genuinely cannot be filled.

#### 6.3.8 Compensation Aggregation

Across all parsed roles where comp was extracted, an aggregate view shows:
- Median, P25, P75 by role family (Senior Backend, Staff Fullstack, etc.)
- Same breakdowns by geography (US, EU, Global Remote)
- Per-company comp ranges where 3+ roles have parsed comp

This is your salary benchmarking tool. No external API needed; just aggregating what the parser has already extracted.

#### 6.3.9 Company Quality Score

A composite score per company, recalculated daily:
- Positive contributions: funding recency, eng activity, blog cadence, hiring volume stability, Glassdoor rating if known, comp transparency
- Negative contributions: layoffs, hiring volume decline, ghost job density, no eng blog, no public OSS, repeated red flags in JDs

Score is exposed as a 1-100 number with a breakdown view ("why this score"). Not used to filter anything automatically; just shown in the company view for reference.

### 6.4 Discovery and Triage Queue

The system that prevents good leads from drowning in noise.

#### 6.4.1 Discovery Sources

Aggregator sources that surface companies I do not yet track:
- HN "Who is Hiring" monthly thread
- Remotive (`software-dev` category)
- We Work Remotely RSS (back-end, fullstack, programming categories)
- Himalayas (with timezone filter for CET)
- RemoteOK
- 4dayweek.io
- (Phase 2) Working Nomads, Adzuna, 80,000 Hours

Each source has a `last_run_at` and `enabled` flag. Sources are managed in `/discover/sources`.

#### 6.4.2 Triage Queue

`/discover` shows the queue of new AggregatorListings with `status = 'new'`, newest first.

Each card shows:
- Parsed company name (prominent)
- Parsed role title(s) and seniority
- Location and remote info, with timezone badge if relevant
- Stack tags
- Compensation if extracted, with currency
- Source badge (HN, Remotive, etc.)
- Posted date and detected date
- Confidence indicators on each parsed field
- "View raw" expander showing the full original posting
- Direct link to the original URL

#### 6.4.3 Triage Actions

Per card:
- **Keep:** opens a modal to either link to an existing tracked company (autocomplete search) or create a new Company from the listing. If create, the form is pre-filled from the listing. After save, status becomes `kept` or `linked`.
- **Dismiss:** mark as `dismissed`, hide from queue. Asks "always dismiss this company?" on 3rd dismiss for the same normalized company name.
- **Snooze:** hide for N days (default 7).

Bulk actions:
- Select multiple, dismiss all
- "Dismiss all from this company" (when same employer posts to multiple aggregators)
- "Dismiss all matching keyword" (e.g., "crypto", "blockchain") with the option to add to a permanent block list

#### 6.4.4 Auto-Dismiss Memory

A `DismissedCompany` table tracks normalized name, dismiss count, and an `auto_dismiss` flag. Listings from auto-dismissed companies arrive with status `auto_dismissed` and bypass the main queue (but are visible in a separate filter).

The auto_dismiss flag is set automatically after 3 manual dismissals of the same company, or manually anytime.

#### 6.4.5 Volume Cap

If a single aggregate run produces more than 200 new listings, only the top 50 by signal (has compensation, marked remote, matches my preferred stack tags) get status `new`. The rest land in status `overflow` and are visible in a separate, dismissable bulk view. Prevents the triage queue from becoming overwhelming during big HN months.

#### 6.4.6 Auto-Linking to Tracked Companies

When a listing's parsed company name matches an existing Company (case-insensitive, normalized, fuzzy match score above 0.85):
- Status becomes `linked`
- The listing is associated with the existing Company
- An Event of type `new_role` is created on that Company
- The listing skips the triage queue and shows directly in the dashboard

This is how the aggregator layer cross-feeds the watch list automatically.

#### 6.4.7 Cross-Source Dedup

Within a 14-day window, if two listings have the same normalized `(company, role_title, location_bucket)`, the second is marked as a duplicate of the first. Visible in UI but collapsed by default.

### 6.5 Search, Filtering, and Saved Views

A platform with ~500 companies and tens of thousands of role rows needs first-class search and filtering, not just a long list with pagination.

#### 6.5.1 Global Search

Cmd/Ctrl-K opens a global search palette (shadcn/ui Command component). Searches across:
- Company names (exact and fuzzy match)
- Role titles
- Dossier content (full-text)
- Event titles and notes
- Contact names

Results group by entity type with keyboard navigation. Postgres full-text search via `tsvector` columns + a GIN index; no separate search service.

#### 6.5.2 Advanced Filters

Faceted filters on the `/companies` and `/roles` pages. Filter dimensions:
- **Companies:** tier (A/B/C/graveyard), status, stage, size, remote policy, domain, primary stack, ATS type, has-open-roles, last_checked_at range, quality score range
- **Roles:** discipline (backend/fullstack/etc.), seniority, is_remote, region, timezone requirement, has_comp, comp range, employment type, posted date range, source, ghost-flagged

Filters are URL-state-backed so I can bookmark or share via copy-paste.

#### 6.5.3 Saved Views

Any filter combination can be named and saved as a Saved View. Examples I might create:
- "Senior remote backend in EU with comp disclosed"
- "Staff fullstack at YC W22+ companies"
- "Stale A-tier" (last_checked_at > 7 days)
- "Recently funded with new senior roles"
- "Possibly ghost jobs"

Saved Views show up in the left navigation under a "Views" group. Order is drag-to-reorder. Default view is editable.

#### 6.5.4 Quick Filters

The top of every list view has 4-6 one-click "quick filter" chips that toggle common filter combinations without opening the full filter panel. Configurable per view.

### 6.6 Tags, Bookmarks, and Personal Annotations

Beyond tiers and status, I need lightweight personal annotations to capture context that doesn't fit the formal schema.

#### 6.6.1 Tags

Free-form tags on Companies and Roles. Examples:
- `interesting-tech` — flag for cool stack regardless of fit
- `founder-i-follow` — companies whose founder I've read or met
- `from-hn` — encountered via Hacker News
- `worth-reaching-out` — even though no role today, contact someone
- `nope-culture` — flag for known bad culture
- `revisit-2027` — too early but worth coming back

Tags are user-defined, autocomplete on add, no taxonomy enforced. Use them to slice via filters or saved views.

#### 6.6.2 Bookmarks (Watch Later)

A "bookmark this role" action on any role card or triage listing. Distinct from the application pipeline (which is for roles I've actually applied to). Bookmarks are my "I want to look at this later, but not now" pile.

`/bookmarks` page shows all bookmarked roles, sortable by date added, posted date, or company quality score. Each bookmark can be promoted to the application pipeline with one click.

Bookmarks auto-expire after 30 days (configurable) with a "you bookmarked this and never looked at it" notification before deletion. Prevents the bookmark pile from becoming an unread-list graveyard.

#### 6.6.3 Stars / Favorites on Companies

A "star" action on Companies, distinct from tier. Stars are my "I really care about this one right now" flag — typically active applications, or A-tier companies I'm watching weekly. Show in a separate dashboard section.

### 6.7 Data Portability and Import

The data is mine. The platform should never lock me in.

#### 6.7.1 Spreadsheet Migration (initial import)

A one-time CSV importer at `/import` that maps a user-uploaded spreadsheet into Companies. Wizard flow:
1. Upload CSV.
2. Auto-detect columns (name, website, tier, status, notes). Manual override for ambiguous columns.
3. Preview first 10 rows mapped to the schema.
4. Dedup by website domain against existing Companies.
5. Run import; show summary of created/updated/skipped.

Supports re-import (idempotent on domain match) so I can fix my source spreadsheet and re-run.

#### 6.7.2 Export

Three export formats, available from `/settings/data`:
- **Full SQL dump** (`pg_dump`) — for backup or migration to another instance.
- **JSON export** — entire database normalized as JSON files (one per entity type). For programmatic consumption.
- **CSV exports** — per-entity (companies.csv, roles.csv, events.csv, contacts.csv, dossiers.csv). For spreadsheets and sharing.

All export buttons trigger a queued job; output is downloadable for 24 hours after generation. Includes a manifest with schema version.

#### 6.7.3 Bulk Operations

From any list view with selection enabled:
- Change tier on multiple companies
- Apply / remove tags on multiple companies
- Dismiss multiple triage listings
- Mark multiple roles as bookmarked / closed

Bulk operations are confirmable, logged in the AuditLog, and reversible within 60 seconds via a toast undo.

---

## 7. Supporting Features

Lighter-weight features that round out the platform. Important, but not where most of the engineering budget goes.

### 7.1 Application Pipeline

A status-tracking view for roles I have actively applied to.

- Kanban-style board with columns: Researching, Applied, Screen, Onsite, Offer, Rejected, Withdrawn
- Cards are Roles with company name and title
- Drag to change status (server action updates DB)
- Per-card detail panel with application date, source (cold/referral/recruiter), contact, next action, next action date, free-text notes
- **Per-stage notes:** within a single application, separate notes per stage (screen feedback, system design feedback, onsite debrief). Append-only with timestamps; nothing lost when status moves forward.
- **Interview prep checklist** per role: lightweight markdown notes I write before each round (talking points, questions to ask, things to research). Pre-populated from the company's dossier where one exists.
- **Offer tracking:** when status reaches Offer, a structured offer panel captures base, equity, bonus, signing, total comp, decision deadline, comparison vs. other active offers.
- Follow-up indicator: cards in Applied or Screen with no movement in 7+ days get a visual flag

Out of scope: resume/CV management, cover letter storage, application form auto-fill, mock interview simulation.

### 7.2 Outreach and Contacts

Light contact log and message tracking.

- Contact list across all tracked companies, filterable by company, role, last touch date
- Message template library (categorized: cold intro, referral ask, follow-up, post-interview, etc.). Just stored markdown.
- Outreach log: free-text record of "I emailed X on Y about Z." Manual entry; no integration with my actual email.
- Follow-up queue: contacts where last_touch was 7+ days ago AND the related company is in "Applied" or "Screen" status

Out of scope: actual email sending, email integration, LinkedIn DM automation. This is a logbook, not a sending tool.

### 7.3 Dashboard

The home page. The first thing I see.

Layout:
- Unseen Events from tracked companies (last 24 hours), grouped by company, with mark-as-seen action
- Discovery queue count badge with link to `/discover`
- A-tier companies sorted by `last_checked_at` ascending, so stale ones float up
- Active applications (Roles with status set), sorted by next action date
- Source health summary (red/yellow/green per source)
- This week's stats: new roles seen, companies added, applications sent, follow-ups due

### 7.4 Notifications

Email-only. Sent via Postmark or Resend.

- **Daily digest** at 7am CET: all unseen Events from tracked companies in the last 24h, plus discovery queue summary
- **A-tier alerts** (immediate): new role at an A-tier company triggers a same-day email within an hour
- **Source health alerts:** scraper down for 3 consecutive runs sends a "fix me" email
- **Weekly summary** (Sundays): stats, top signals of the week, companies whose quality score changed materially

All notifications respect a "do not disturb" window (default: 10pm to 7am CET).

### 7.5 Settings

Minimal. Configuration files where reasonable, UI where needed:
- Notification email and DND window
- Default tier for newly-discovered companies
- Volume cap threshold
- Auto-dismiss threshold (default 3)
- Polling cadences per tier
- Source enable/disable toggles

Single profile, no themes, no multi-user UI.

### 7.6 Authentication

The platform has one user (me) but is exposed to the public internet, so it needs real auth. The model is "private single-tenant tool with login," not "SaaS with sign-ups."

**Auth model:**
- One pre-seeded tenant and one pre-seeded user account belonging to that tenant. No public registration page.
- Email + password login, with the option to require a TOTP second factor.
- Sessions, not API tokens. Inertia-friendly. Standard Laravel session cookies, signed and encrypted, `SameSite=Lax`, `Secure`, `HttpOnly`.
- Remember-me cookie supported with a long expiry (30 days) so I don't re-login from my laptop every week.
- Brute-force protection via Laravel's built-in rate limiter on the login route (5 attempts per minute per IP, then a 15-minute lockout).

**Implementation:**
- **Laravel Fortify** as the auth backend. Headless, no Blade views, pairs cleanly with Inertia + React. Provides login, logout, password reset, password confirmation, and 2FA out of the box.
- **No third-party SSO** (Google, GitHub, etc.) in v1. Single email/password account avoids dependency on external auth providers and keeps the setup trivial for a private tool.
- Password reset works via email (sent through the same Postmark/Resend account used for notifications).
- 2FA via TOTP (Google Authenticator, 1Password, Bitwarden, Authy). Optional but recommended for me to turn on. Recovery codes generated and downloadable on enablement.

**Registration disabled:**
- No `/register` route is exposed publicly.
- The initial tenant is created via a database seeder (`php artisan db:seed --class=CreateInitialTenantSeeder`) reading the tenant name/slug from `.env`. Idempotent.
- The initial user is created via a database seeder (`php artisan db:seed --class=CreateAdminUserSeeder`) reading credentials from `.env` and attaching the user to the initial tenant. Re-running the seeder is a no-op.
- A `php artisan user:reset-password` command exists for offline recovery if I lock myself out.
- A `php artisan tenant:create` command exists but is not surfaced in the UI. It exists so that a future "let a friend in" decision is a one-line operation. It writes to the AuditLog.

**Account management UI (`/settings/account`):**
- Change email
- Change password (requires current password)
- Enable / disable 2FA
- View and regenerate recovery codes
- View active sessions (device, IP, last activity) with revoke buttons
- "Sign out everywhere" button

**What stays public without auth:**
- The login page itself
- A health-check endpoint at `/up` returning 200 if the app is alive (no DB queries, no auth, no leakage of internal state)
- Static assets

Everything else (dashboard, scrapers' admin UI, settings, every data view) requires a valid session. Middleware on the route groups, not per-controller.

**Out of scope for auth (v1):**
- Public registration
- Multiple users or team accounts
- Role-based permissions (no admin vs. user distinction; one user = everything)
- OAuth / social login
- SAML / SSO
- Magic-link login (could revisit later, but TOTP + password is the safer default)
- API tokens for the platform's own API (because there is no public API in v1)

### 7.7 Calendar and Important Dates

A lightweight date tracker so I don't lose interview times, application deadlines, follow-up moments, and equity vesting cliffs in disparate places.

- **Events on entities:** Roles, Companies, and Contacts can have associated `CalendarEvent` rows with title, datetime, timezone, type (interview, deadline, follow-up, reminder, custom), notes, and optional URL (Zoom link, Calendly, etc.).
- **Dashboard widget:** "Upcoming" section showing the next 7 days of CalendarEvents sorted chronologically.
- **iCal feed export:** Each user has a private, token-protected `.ics` URL (e.g., `/calendar/{secret}/feed.ics`) so I can subscribe from Apple Calendar, Google Calendar, Fantastical, or Thunderbird. Read-only.
- **Reminders:** Email notification N minutes before each CalendarEvent (default 30 min, configurable per event).

Out of scope: actual calendar integration (writing back to Google/Apple), invitee management, recurring events. This is a logbook with iCal output, not a calendar app.

### 7.8 Observability and Debugging

For a tool I'll iterate on over months, I need to see what the platform is doing, why scrapers failed, and where parser confidence dropped — without SSHing into the box and tailing logs.

- **Scrape run history:** `/admin/runs` page lists every scheduled run with start/end time, duration, source breakdown (fetched / new / errors), and a drill-in view per run.
- **Per-run drill-in:** Per-source error log, per-company fetch status, parser confidence histogram, raw HTTP response viewer (for the 7-day retention window).
- **Parser stats dashboard:** Per-source, per-field confidence trend over the last 30 days. Companies where parsing has consistently failed are highlighted for manual inspection.
- **Audit log viewer:** `/admin/audit` shows the security AuditLog (auth events) plus a system-level activity log (tier changes, graveyard moves, bulk operations, parse corrections) for the last 90 days. Filterable by actor (always me), entity, and date.
- **Laravel Telescope:** Installed in production behind auth, with retention of 7 days. For HTTP/cache/log inspection during real debugging.
- **Health endpoint:** Public `/up` (200 if alive, no internal state leaked). Private `/health` (behind auth) with detailed JSON: DB connectivity, last successful scrape per source, queue depth, disk free.

### 7.9 Configuration and Environment

Settings live in three places, by design:

- **Environment variables (`.env`):** secrets only — DB credentials, mail driver API keys, S3/B2 credentials, `APP_KEY`, the initial seeded user email/password (consumed once by seeder, then irrelevant). Never committed.
- **Config files (`config/*.php`):** static, version-controlled behavior — polling cadences per tier, volume cap thresholds, parser confidence thresholds, dictionary paths. Changed via git commit + deploy.
- **Database-backed settings (`/settings/*` UI):** runtime-tunable values that I'd want to change without a deploy — notification preferences, DND window, auto-dismiss threshold, dismissed-listing retention days, source enable/disable, daily digest send time.

A single `Setting` model with a key-value JSON store backs the database-backed settings. Defaults come from config files; database overrides take precedence. Easy to reason about, easy to back up.

### 7.10 Mobile and Responsive Design

I'll check this on my phone occasionally — usually to triage HN listings on transit or glance at A-tier alerts. Not a mobile app, but the web UI needs explicit responsive treatment.

- All pages must work at 375px wide (small phone) without horizontal scrolling.
- Triage queue, dashboard, and company detail are the priority mobile views; pipeline kanban and admin pages can be desktop-first.
- Tap targets >= 44px on touch.
- Navigation collapses to a hamburger drawer below 768px.
- Tables horizontally scroll within their container, never breaking the page layout.
- No mobile-specific UI patterns (pull-to-refresh, swipe actions). Standard responsive only.

Tailwind's default breakpoints; React components built mobile-first.

### 7.11 Onboarding and First-Run Experience

First time I log into a fresh install, the app should walk me from empty to useful in under 15 minutes.

- **First-run wizard** (shown when companies table is empty):
  1. Welcome screen with a 2-line pitch.
  2. Set your timezone and notification email.
  3. Import from CSV (with a downloadable sample template), or skip.
  4. Pick from a curated starter list of 20 well-known remote-friendly companies grouped by category (devtools, fintech, AI, infrastructure). Multi-select to seed at C-tier.
  5. Run first scrape immediately (foreground, shows progress).
  6. Land on dashboard with newly-discovered roles populated.
- **Empty states:** every list view has a context-aware empty state with a CTA. "No companies yet" → button to add or import. "No new events" → recap of last successful scrape with timestamp.
- **In-app guidance:** Each major page has a "?" icon that opens a slide-out panel explaining what the page does and what the key actions mean. Dismissible permanently per page.

### 7.12 Internationalization

Not in scope. The UI ships in English only. All dates use the user's timezone (set in profile, defaults to CET for Skopje). All currency normalizes to USD for parsing and display. Stating this explicitly to prevent scope creep.

---

## 8. Non-Functional Requirements

### 8.1 Performance
- Full scrape run (all sources, all companies) completes in under 90 seconds.
- Dashboard loads in under 200ms server response time.
- No page in the UI takes more than 500ms p95.

### 8.2 Resilience
- Per-source isolation: one failing source never breaks the whole scrape.
- Per-company isolation: one failing fetch never breaks other companies on the same source.
- Idempotent ingestion: re-running a scrape produces the same DB state, no duplicates.
- Graceful degradation: if a source goes down for a week, the dashboard still works, just with stale data and a visible warning.

### 8.3 Data Preservation
- Soft-delete only. Closed roles are marked `is_open = false`, not deleted.
- Daily snapshot of every company's open-role count, retained for 2 years (for the trend chart).
- Event log is append-only. No deletions.
- Database backed up nightly to S3 or Backblaze B2.

### 8.4 Cost
- Target monthly run cost: under $30, with a stretch goal of under $20 once Laravel Cloud usage stabilises.
  - Laravel Cloud compute (1 environment, low traffic): roughly $10-20/mo depending on idle scaling and scrape burst usage.
  - Laravel Cloud Postgres (smallest tier, EU region): roughly $5-10/mo.
  - Postmark or Resend (notifications): $3-5/mo.
  - Domain amortized: $1/mo.
  - Off-platform backup storage (Backblaze B2 for the periodic `pg_dump` exports): $1-2/mo.
- No third-party paid APIs. All data sources are free.
- If Cloud usage materially exceeds this envelope, revisit by tuning idle-scale, moving scrape workloads to scheduled tasks (which sleep between runs) rather than always-on workers, or downsizing the Postgres tier.

### 8.5 Compliance
- All ATS public API usage attributes the source where required (Remotive, WWR).
- Respect each source's published rate limits.
- User-Agent string identifies the bot and provides contact info.
- No PII stored beyond what I manually enter for contacts.
- Database and compute pinned to an EU Laravel Cloud region (Frankfurt or equivalent) for GDPR comfort.

### 8.6 Security
- HTTPS everywhere. TLS termination, certificate provisioning, and renewal are managed by Laravel Cloud's edge (FrankenPHP behind Cloud's load balancer); no Caddy or Let's Encrypt to operate by hand.
- HSTS header enabled with a 1-year max-age after the first Cloud deploy confirms HTTPS works end-to-end on the custom domain.
- Standard security headers via middleware: `Content-Security-Policy`, `X-Frame-Options: DENY`, `Referrer-Policy: strict-origin-when-cross-origin`, `X-Content-Type-Options: nosniff`.
- Passwords hashed with bcrypt at Laravel's default cost (currently 12), upgraded automatically when the default rises.
- CSRF protection on every state-changing request (Inertia's `_token` handling makes this transparent).
- Rate limiting on login (5/min/IP) and password reset (3/hour/IP).
- Session fixation protection: regenerate session ID on login.
- Sensitive environment variables (`APP_KEY`, DB password, Postmark/Resend keys, B2 credentials) live in Laravel Cloud's encrypted secrets store for production and `.env` locally. Never committed. Rotated quarterly.
- Database connections use TLS (enforced by Laravel Cloud Postgres; verified locally against the Spin Postgres container with `sslmode=prefer`).
- Off-platform `pg_dump` backups encrypted at rest in Backblaze B2 via B2's server-side encryption. Laravel Cloud's managed backups are encrypted at rest by Cloud itself.
- All admin actions (login, 2FA changes, password changes, session revocation) get logged to a dedicated `AuditLog` table with timestamp, IP, user-agent, and outcome.

### 8.7 Operability
- Deployments via Laravel Cloud: `git push` to `main` triggers a Cloud build and deploy. The `cloud` CLI handles environment configuration, secrets, and manual rollbacks.
- Logs are queryable through Laravel Cloud's log viewer with retention configured per environment; Telescope is installed in production behind auth (7-day retention) for HTTP/cache/job inspection during real debugging.
- Health check endpoint (`/up`) returns 200 if the app is alive (no DB query). A private `/health` endpoint (auth required) returns detailed JSON: DB connectivity, last successful scrape per source, queue depth.
- Backup strategy: Laravel Cloud's managed Postgres backups (point-in-time recovery) are the primary safety net; a scheduled `php artisan backup:run` pushes a `pg_dump` to Backblaze B2 nightly as off-platform insurance. Restore tested quarterly against both sources.

### 8.8 Time, Dates, and Time Zones

- All timestamps stored as `TIMESTAMPTZ` in Postgres, UTC.
- Single-user timezone preference (defaults to `Europe/Skopje`) used for display, daily-digest scheduling, and DND window calculation.
- All comparisons between dates from different sources (HN posted_at, Greenhouse updated_at, my local "now") happen in UTC.
- The UI shows both relative ("3 days ago") and absolute (in my timezone) where useful, with absolute on hover.
- iCal feed exports CalendarEvents in UTC with the original timezone preserved in `TZID`.

### 8.9 Storage and Growth

Capacity planning for a multi-year run:
- 500 tracked companies × 5 roles average × 4 fetches/day × 365 days ≈ 3.6M role-snapshot rows per year.
- Events grow at ~50,000-100,000 rows/year.
- AggregatorListings (with 90-day retention) hold ~15,000 rows at steady state.
- Raw HTTP response artifacts (7-day retention) consume ~500 MB rolling.
- Full DB at year 2 estimate: 8-12 GB. Postgres handles this comfortably on a $5 VPS.

Mitigations if growth exceeds expectations:
- Partition the `Role` snapshot history table by month.
- Move closed-Role rows older than 1 year to a `RoleArchive` table.
- Increase B2 backup retention tiering (daily for 30 days, weekly for 6 months, monthly indefinite).
- A `db:vacuum-deep` artisan command for periodic `VACUUM FULL` during downtime windows.

### 8.10 Accessibility

Single-user tool, but I want it to be pleasant to use long-term and not regret accessibility choices later:
- Keyboard navigation: Cmd/Ctrl-K global search, j/k navigation in list views, Esc closes modals, Enter confirms.
- Focus rings visible on all interactive elements (no `outline: none` without replacement).
- Color contrast WCAG AA minimum (4.5:1 for text). The editorial palette in the design system already meets this.
- Form labels and error messages associated programmatically.
- `prefers-reduced-motion` respected for transitions.
- `prefers-color-scheme` supported (dark mode optional, light default).

---

## 9. Technical Architecture

Brief, since this is a personal tool. Detailed design lives in separate ADRs.

- **Framework:** Laravel 13 (released 17 March 2026). Headline gains for this project are the new PHP 8 attributes across models / controllers / jobs (`#[Table]`, `#[Middleware]`, `#[Authorize]`, `#[Tries]`, `#[Backoff]`, `#[Timeout]`, `#[FailOnTimeout]`), centralized queue routing via `Queue::route(...)`, and the hardened `PreventRequestForgery` middleware with origin-aware verification. The first-party Laravel AI SDK shipped stable with 13 — we will **not** use it (no AI features in scope) but having it in the framework is harmless.
- **PHP:** 8.5 (released 20 November 2025). Laravel 13's minimum is PHP 8.3, so 8.5 is well within the supported range. We pick up the pipe operator (`|>`) which is genuinely nice for the scraper pipeline (parse → normalize → validate → persist), the new `Uri\Rfc3986\Uri` extension for parsing job-posting URLs without relying on legacy `parse_url()`, the `clone()` with-properties syntax for value-object-style models, and `#[\NoDiscard]` for marking methods like scraper-result objects whose return values should not be silently ignored.
- **Web server (production):** FrankenPHP managed by Laravel Cloud, running in classic (request-per-process) mode in v1. Laravel Cloud handles TLS, edge routing, and scaling; we get the FrankenPHP runtime without owning the systemd / Caddy / Let's Encrypt operational surface. Worker mode (via Octane) is *not* enabled in production v1; revisit if and when performance pressure justifies the operational cost.
- **Web server (local):** FrankenPHP via the `serversideup/php` Docker images, orchestrated through Spin (Docker Compose). Local domain is `vantagejobs.test`. The Spin `dev` overlay is the source of truth for which services run locally; `ci` mirrors it for CI parity. The local `php` service boots FrankenPHP under Octane (worker mode) as scaffolded by the Spin Pro template; this is a deliberate dev/prod runtime mismatch we accept in exchange for keeping the scaffolded local stack intact. Implication: any singleton/static state we introduce must reset cleanly per request, since prod will reset it for us but dev won't catch the leak. Octane bugs that don't manifest in prod (classic mode) but surface locally (worker mode) get fixed at the singleton level, not papered over.
- **Database:** PostgreSQL 18, managed by Laravel Cloud (single instance, no replicas). Latest stable as of May 2026 (18.4 released 11 May 2026). We get the operational improvements landed in 18 (asynchronous I/O subsystem, faster vacuuming, native UUIDv7 generation, improved query planner heuristics) for free; none of those features are required, but they're nice to have on a system that does heavy upsert traffic from scrapers. Locally, Postgres 18 runs as a Spin service.
- **Frontend:** Inertia.js bridging Laravel and React 19. TypeScript. Server-driven routing, no separate API layer.
- **Styling:** Tailwind CSS v4. shadcn/ui for primitives (Button, Dialog, Card, Table, etc.).
- **State:** TanStack Query for any client-side fetching beyond Inertia page loads (e.g., scrape status polling, parser stats). Zustand if we ever need shared client state, which we probably won't.
- **Forms:** Inertia's `useForm` hook for everything. Server validation via Laravel Form Requests, errors flow back through Inertia's shared props.
- **Auth:** Laravel Fortify (headless, Inertia-friendly). Email + password with optional TOTP 2FA. Session-based, no API tokens in v1. Single seeded user, no public registration. See section 7.6.
- **Multi-tenancy:** Single-database, single-schema, row-level tenancy. Every domain table carries a `tenant_id` foreign key (Company, Role, Event, Source, Note, AuditLog, AggregatorListing, Contact, Dossier, CalendarEvent, ParseCorrection, ScrapeRun, ScrapeRunArtifact, Bookmark, Template, Setting, Tag, CompanySnapshot, DismissedCompany). A `BelongsToTenant` trait applies a global Eloquent scope that filters on the resolved current tenant, and a model observer auto-sets `tenant_id` on insert. A `TenantContext` service is the single source of truth for "who am I serving right now," resolved from `auth()->user()->tenant_id` on web requests and from `$tenant->setCurrent()` inside artisan commands and scheduled tasks. Cross-tenant access is impossible by construction in the application layer; nothing in the v1 UI offers a tenant switcher because there is only one tenant. No `stancl/tenancy` or `spatie/laravel-multitenancy` package in v1: hand-rolled is fewer moving parts and easier to reason about for the scale we operate at. Revisit if/when v2 ever wants subdomain routing or per-tenant database isolation.
- **Background jobs:** Laravel's scheduler runs as a Cloud scheduled task in production (no host cron to babysit) and as a `schedule:work` long-running process locally. Long-running scrapes are artisan commands invoked by the scheduler. Horizon is installed and runs as a worker service in the local Spin stack (against the local Redis service), but in v1 no jobs are dispatched to queues — scrape and signal commands run synchronously inside the scheduled task. Locally, the Horizon worker is essentially idle; we keep it running so that the moment a Phase 6+ workload appears, the wiring is already there. Production stays minimal: no Horizon, no Redis, no queue workers in v1.
- **HTTP client:** Guzzle via Laravel's `Http` facade, with `Http::pool()` and raw Guzzle `Pool` for concurrent fetches with per-host concurrency caps.
- **Build tooling:** Vite, with Laravel's official Vite plugin and the Inertia React preset. Frontend asset builds run inside the `node` Spin service locally and on Laravel Cloud's build step in production.
- **Deploy target:** Laravel Cloud, EU region (Frankfurt or equivalent for GDPR). Single environment in v1 (`production`); a `staging` environment can be added later if I ever need a real preview before pushing to prod.
- **Deploy mechanism:** `git push` to `main` triggers Laravel Cloud's build pipeline (composer install, `npm ci && npm run build`, `php artisan migrate --force`, config/route/view cache). The `cloud` CLI handles secrets, environment variables, rollbacks, and ad-hoc shell access.
- **Backups:** Laravel Cloud managed Postgres backups (point-in-time + nightly snapshots) as primary. A scheduled `php artisan backup:run` exports `pg_dump` to Backblaze B2 nightly for off-platform redundancy.
- **Tooling (already installed in the scaffold):** Laravel Boost (MCP server with project-aware tools), Laravel Pail (live log tail), Laravel Pint (code formatter, project standard is `--format agent`), Spin (Docker Compose wrapper for dev/CI), PHPUnit 12. Local execution rule: every `php`, `artisan`, `composer`, `vendor/bin/*`, and `npm` command runs through `spin run php` / `spin run node` (or `spin exec` when the stack is up); host-side invocation is not supported.

Why FrankenPHP in classic mode in production, not worker mode: we discussed this. Worker mode optimizes for high RPS and amortized boot cost across many concurrent requests. A single-user dashboard sees ~50-200 page views per day. Worker mode buys nothing in production here and introduces real costs: memory leaks from singletons that don't reset per request, weirder debugging, package compatibility surprises. Classic FrankenPHP still gives us the things that matter: managed runtime on Cloud, modern deployment story, and a clean path to flip to worker mode if and when usage justifies it.

Why local runs Octane (worker mode) anyway: the scaffold from the Spin Pro template wires up Octane, Horizon, and Redis as a complete local stack out of the box. Ripping those out for v1 would be net-negative — we'd lose dev/prod *infrastructure* parity for Phase 6+ work (when Horizon + Redis will be real production dependencies) and gain only theoretical purity. The accepted trade-off is dev/prod *runtime mode* divergence: prod is classic, dev is worker. We mitigate by being disciplined about singleton state and by making sure any code under `app/` that holds mutable static state has a clear lifecycle.

Why tenant-aware schema in v1 when there's only one user: retrofitting tenancy onto a single-user schema two years in is a known-painful migration (every domain table, every query, every test, every export). Front-loading the `tenant_id` column plus the `BelongsToTenant` global scope is cheap (one trait, one observer, a handful of migrations), invisible at runtime when there's only one tenant, and free to leave dormant. The exit cost is what we're optimising against, not the entry cost.

Why Laravel Cloud, not a Hetzner VPS: an earlier draft of this PRD targeted a single-VPS deploy via Forge or Ansible. Reversed for two reasons. First, operating a VPS for a personal tool is exactly the kind of long-tail toil this project is supposed to remove from my life; TLS renewal failures, kernel upgrades, Postgres point-releases, and disk-fill incidents are not work I want to do on weekends three years from now. Second, Cloud's pricing for a low-traffic single-user app is within striking distance of the VPS-plus-managed-Postgres total once you account for backups, monitoring, and time. Lifting the operational floor by ~$10-15/mo is a trade I'm willing to make. The escape hatch is intact: Laravel Cloud apps are still Laravel apps, and a future migration back to a VPS or to a different managed platform is a few days of work, not a rewrite.

Why Inertia + React, not Livewire or a separate SPA: Inertia adds a TypeScript and React build step, but gains us a real component ecosystem (shadcn/ui, lucide icons, recharts), better client-side ergonomics for the Kanban pipeline and the triage queue, and skill transfer to other projects. Inertia avoids the tax of building a separate API plus SPA: no API design, no auth tokens, no CORS, no duplicated validation. Each page is a Laravel controller returning an Inertia response with a typed page-props object; the React side just renders.

Why not SQLite: company-quality-score recalculation and analytics queries (hiring volume trends, comp aggregation, signal cross-referencing) benefit from real Postgres. SQLite is fine for the data volume but loses on concurrent writes (scraper + dashboard) and analytical SQL. Postgres is boring and free.

---

## 10. Phasing and Milestones

Six phases, each shippable on its own. Each phase ends with the platform being usable, not partly built.

### Phase 0: Foundation (1 weekend)
Laravel 13 scaffold (already in repo) on PHP 8.5 with FrankenPHP (classic mode) via Spin locally and Laravel Cloud in production. Postgres 18, Tailwind v4, Inertia + React + TypeScript with shadcn/ui primitives. Tenancy primitives in place: `tenants` table, `Tenant` model, `BelongsToTenant` trait + observer, `TenantContext` service, and `tenant_id` columns on every domain table. Base schema for Tenant, User, Company, Role, Event, Source, Note, AuditLog. Fortify configured with login, password reset, and TOTP 2FA support. Single tenant and single user seeded from `.env`. Registration route disabled. First-run wizard for empty state. Seed with 10 real companies (mix of Greenhouse, Lever, Ashby), all attached to the seeded tenant. First Laravel Cloud deploy with HTTPS on the custom domain. Responsive layout shell.

**Ship criterion:** I can log in, log out, change my password, enable 2FA, and see a placeholder dashboard. The platform is deployed to Laravel Cloud, served over HTTPS on the production domain, and exposed to the public internet safely.

### Phase 1: ATS Pipeline (1-2 weekends)
Scrapers for Greenhouse, Lever, Ashby. Concurrent fetching via Guzzle Pool. Diff and dedup logic. Events created for new roles. Companies UI (list, create, edit, detail) with basic filters. Working scrape command runnable from cron. CSV import wizard for spreadsheet migration. Tags model on Companies. Global search palette (Cmd/Ctrl-K) for Companies and Roles.

**Ship criterion:** I can import my spreadsheet, add new companies, watch them get scraped, see new roles appear, and find anything with Cmd-K.

### Phase 2: Aggregators and Triage (1 weekend)
Scrapers for HN "Who is Hiring", Remotive, WWR RSS, Himalayas, RemoteOK. Parser layer with extractors for company, role, location, remote, comp, stack, seniority. Triage queue UI at `/discover` with keep/dismiss/snooze. Auto-linking to tracked companies. Auto-dismiss memory and indicators. Bookmarks (watch later) feature.

**Ship criterion:** I can review and process HN's monthly thread in 15 minutes flat.

### Phase 3: Parser Polish and Corrections (1 weekend)
Parse confidence scoring. Inline correction UI. ParseCorrection table. "Save as test fixture" workflow. Parser stats dashboard at `/admin/parser`. Raw scraper artifact retention (7 days). Scrape run history viewer at `/admin/runs`. Advanced filtering + Saved Views.

**Ship criterion:** When I correct a bad parse, the platform learns from it; I can build and save complex filter combinations.

### Phase 4: Signals (1-2 weekends)
Hiring volume trend charts (per company and global). Layoff scrape and cross-reference. GitHub org activity poller. Engineering blog RSS monitor. Funding event tracking. Ghost-job and boomerang detection. Company quality score. Company graveyard view with reason tags.

**Ship criterion:** When I view a company, I have enough signal to decide whether to escalate it to A-tier without further research.

### Phase 5: Pipeline and Outreach (1 weekend)
Kanban pipeline view. Application detail panel with per-stage notes and interview prep checklist. Offer tracking. Contact CRM (light). Message template library. Follow-up queue. Calendar Events on Roles/Companies/Contacts. iCal feed export.

**Ship criterion:** I can run an active application process end-to-end without leaving the platform, including tracking offers and upcoming interview times.

### Phase 6: Polish (1 weekend)
Daily and weekly digest emails. A-tier instant alerts. Source health monitoring with auto-alerting. Settings UI consolidated. Data export (SQL, JSON, CSV). Bulk operations on list views. Backup automation. Accessibility audit pass. Documentation. Final responsive review.

**Ship criterion:** I can hand the platform to future-me six months from now and it still runs without intervention.

Total estimated build time: 9 to 13 weekends. Reality is probably 16 to 20 by the time you account for life and the inevitable rabbit holes. Each phase is shippable on its own; if Phase 4 ends up being two weekends instead of one, that's expected.

---

## 11. Risks and Decisions Log

### 11.1 Risks

**ATS slug auto-detection accuracy.** If detection fails often, manual entry friction kills the bulk-import experience. Mitigation: start with manual entry as default, treat auto-detection as a convenience.

**HN parser maintenance burden.** The "Who is Hiring" format drifts over time. If the parser breaks silently, I lose a high-signal source without noticing. Mitigation: a small test fixture suite of real postings from each year, run on every parser change.

**Source ToS shifts.** Any source could change terms or rate limits without notice. Mitigation: per-source health monitoring with email alerts, plus a "swap to backup" pattern documented per source.

**Data growth.** At 500 tracked companies polled every 6h with full role history retained, the DB will grow. Estimate: 500 companies × 5 roles average × 4 fetches/day × 365 days = ~3.6M role-snapshot rows/year. Postgres handles this fine but indexes and partitioning may be needed by year 2. On Laravel Cloud, growth also nudges the managed-Postgres tier and the monthly bill, so watch storage as a cost signal, not just a performance one. Mitigation: aggressive use of soft-delete on closed roles and a yearly archive script.

**Self-discipline.** The tool only works if I actually use it. Mitigation: the daily digest forces touchpoints. If I notice I'm ignoring digests for a week, that's a signal the tool is failing me, not me failing the tool.

**Burnout building it.** Eight weekends is realistic. Eighteen is not (compared to other commitments). Mitigation: every phase is shippable on its own. Stop at Phase 3 if needed and the tool is still useful.

### 11.2 Resolved Decisions (was: Open Questions)

These were open during PRD drafting and have since been resolved:

- **Public read-only API for external tools (Raycast, CLI):** Not in v1. Revisit after Phase 6 ships and the platform is stable.
- **Company graveyard view:** In scope. See section 6.2.8.
- **Dismissed AggregatorListing retention:** 90 days, configurable in settings. Hard-delete (not soft-delete) on expiry to keep the table lean.
- **Compensation cost-of-living adjustments:** Not in v1. Raw nominal USD-normalized values only. Revisit when actually comparing offers across geographies.
- **Company switching ATS mid-scraping:** Detect via 404s on the old ATS slug for 7 consecutive scheduled runs, then mark the company's ATS as `needs_redetection` and queue a re-detection job that crawls the careers page and updates the slug. If re-detection fails, surface an alert in the source health dashboard.
- **Raw scraper HTTP response retention:** 7 days, configurable. Stored in a `ScrapeRunArtifact` table or as compressed blobs on disk. Used for debugging parser regressions and source structure changes.
- **Auto-dismiss indicators:** Yes. Auto-dismissed companies surface a small "auto-dismissing employer X (Y dismissals)" banner on the dashboard when relevant, plus a settings page to review and revoke active auto-dismiss rules.
- **Graveyard data handling:** Keep historical Roles and Events in place. Excluded from default queries; lazy-loaded only when the graveyard view explicitly requests them. No cascade-delete on graveyarding.
- **Parser confidence threshold for auto-linking:** Hard-coded at 0.85 in v1. If the false-positive or false-negative rate becomes a problem in practice, promote to a database-backed Setting in a future patch.
- **CSV importer column support:** Out of scope as a separate concern. The wizard auto-detects whatever columns exist in the uploaded CSV with manual override; no fixed schema. Free-form notes field stays as a catch-all destination for unmapped columns.
- **Watchlist of people:** Not in scope. Deferred indefinitely. Following individual engineers across companies is interesting but adds enough surface area (people identity resolution, LinkedIn integration pressure, privacy considerations) that it deserves its own future PRD if it ever happens.
- **Hosting target:** Laravel Cloud (EU region), not a self-hosted Hetzner VPS. Earlier drafts targeted a $5 Hetzner box managed via Forge or Ansible. Reversed in v3 of this PRD: see section 9 for the rationale (lower operational toil over a multi-year horizon, acceptable cost delta for a single-user app). VPS plus Forge stays viable as a future migration path if Cloud pricing or behavior becomes a problem.
- **Local dev stack:** Spin (Docker Compose) wrapping `serversideup/php` images. All artisan, composer, npm, pint, and test commands run through `spin run php` / `spin run node` (or `spin exec` when the dev stack is up). Host-side PHP invocation is not supported. This is project policy, codified in CLAUDE.md and the README.
- **Already-installed tooling kept from the scaffold:** Laravel Boost (project-aware MCP server), Laravel Pail (live log tail), Laravel Pint (formatter, run with `--format agent`), Spin (Docker wrapper), and PHPUnit 12. Horizon, Octane, and Redis are installed and **run locally** in the Spin stack out of the Pro template, but are **disabled in production** in v1: prod uses FrankenPHP classic mode (no Octane worker), no Horizon, no Redis. Local Horizon/Octane/Redis are scaffolding for Phase 6+; they sit idle in v1 because no code dispatches to queues. Trade-off accepted: dev/prod runtime parity (Octane vs classic) is the cost, dev/prod infrastructure scaffolding is the win.
- **Multi-tenancy (v3 decision):** Hand-rolled, single-database, single-schema, row-level tenancy from day one. Every domain table carries `tenant_id`. `BelongsToTenant` trait applies a global Eloquent scope and a model observer auto-fills `tenant_id` on insert. `TenantContext` service resolves the current tenant per request (from `auth()->user()->tenant_id`) and per artisan command (explicit `$tenant->setCurrent()` at the top of any command that touches tenant-scoped data). One seeded tenant in v1, no UI to switch tenants, no public `/register` route. See section 9 for the rationale.

### 11.3 Currently Open Questions

None at this time. All previously open questions have been resolved and moved to section 11.2. New questions arising during implementation will be added here.

---

## 12. Explicitly Out of Scope

To prevent scope creep, the following are NOT v1 features and require an explicit scope change to add:

- **Any AI/LLM feature.** No GPT-powered parsing, matching, summarization, or chat. Heuristic parsing with manual correction loop is the only intelligence layer.
- **Resume management.** No CV storage, versioning, or generation.
- **Cover letter generation.** Out.
- **Auto-apply.** Never.
- **LinkedIn integration.** No scraping, no DM automation, no contact sync.
- **Multi-tenant UX.** The schema is tenant-aware, but v1 ships one tenant, no sharing UI, no comments, no team features, no tenant switcher. Adding a second tenant is an artisan command, not a product feature.
- **Browser extension.** Maybe ever. Not v1.
- **Mobile app.** The web UI is responsive enough.
- **Calendar integration.** Manual entry of interview dates is fine.
- **Email integration.** Logging only, no sending or syncing.
- **Public job posting board.** This is not a job board.
- **Recruiter side / employer side anything.** This is a candidate tool.
- **Notion/Airtable sync.** Database is the source of truth.
- **Real-time anything.** Daily and per-event-tier polling is enough.

---

## 13. Appendices

### Appendix A: Glossary
- **Tenant:** The top-level data ownership boundary. Every domain row belongs to exactly one Tenant via `tenant_id`. In v1 there is exactly one Tenant (mine); the schema and code paths are tenant-aware so a future second tenant is a seeder change, not a refactor.
- **TenantContext:** The application-layer service that knows which Tenant is "current" for a given request, command, or job. Drives the global Eloquent scope applied by `BelongsToTenant`.
- **Company:** A canonical employer entity in the watch list.
- **Role:** A specific job posting at a Company, tied to one external ID from the source ATS.
- **Event:** A timestamped fact about a Company (new_role, funding, layoff, blog_post, hire, departure, restored, my_note).
- **AggregatorListing:** A raw job posting from a discovery source (HN, Remotive) that has not yet been triaged.
- **ParsedListing:** The structured output of running the parser pipeline on a raw posting.
- **Dossier:** A long-form markdown document attached to an A-tier Company.
- **Tier:** A, B, or C classification of a Company. Drives polling cadence and alert behavior.
- **Graveyard:** A retired Company that I once tracked and have de-prioritized. Excluded from default queries; visible only when explicitly requested.
- **Triage:** The act of reviewing an AggregatorListing and deciding to keep, dismiss, or snooze it.
- **Tag:** A user-defined free-form label on a Company or Role, used for slicing and saved views.
- **Bookmark:** A "watch later" flag on a Role, distinct from the application pipeline. Auto-expires after 30 days by default.
- **Star:** A "currently active interest" flag on a Company. Drives a dedicated dashboard section.
- **Saved View:** A named, URL-state-backed filter combination accessible from the left navigation.
- **CalendarEvent:** A datetime-anchored item on a Role/Company/Contact (interview, deadline, reminder). Exposed via the iCal feed.
- **ScrapeRunArtifact:** A raw HTTP response retained for 7 days for debugging parser/scraper regressions.
- **AuditLog:** Append-only record of security-sensitive actions (login attempts, password changes, 2FA changes, session revocation).
- **Setting:** A runtime-tunable, database-backed configuration value editable via the UI without a deploy.
- **Fortify:** Laravel's headless authentication backend, used here as the auth implementation.

### Appendix B: Source Inventory (Phase 1 + 2)

See the separate sourcing-map document for the full list of 20 prioritized sources with endpoints, rate limits, and integration notes.

### Appendix C: References
- Sourcing Map research document (Skopje to Global Remote, May 2026)
- Parser layer extension prompt (covers ParsedListing types and confidence model)
- Aggregator extension prompt (HN, Remotive, WWR, RSS sources)

---

**End of PRD v1.**
