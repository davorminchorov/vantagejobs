# Changelog

Per-phase shipped scope for VantageJobs. One section per phase, newest last. Each phase shipped on its own and is detailed in the matching `PHASE_N_NOTES.md`. Format follows the project's "every phase is shippable" rule, not semver.

---

## Phase 6 — Polish + Notifications

**Ship criterion:** Live with the tool — digests, alerts, and a weekly summary keep me touching it; settings let me tune it; bulk ops + undo make triage fast; my data is exportable; backups are real and verified.

- **Daily digest** (`digest:send-daily`): hourly tick gated on each recipient's local send hour; skips empty days by default (`digest_skip_empty` Setting).
- **A-tier instant alerts** (`notifications:send-a-tier-alerts`): high-signal new roles on A-tier companies, throttled to 1 email/company/hour via `notification_log`; DND window defers (does not drop).
- **Weekly summary** (`notifications:send-weekly-summary`): per-tenant, day-gated inside the command.
- **Source-health monitor** (`monitor:source-health`): hourly sweep over `AggregatorSource` failure counts + `ParserConfidenceDaily` WoW confidence; deduped fix-me email per red source.
- **Bulk operations** (`POST /bulk-actions`): change tier, tag/untag, dismiss, bookmark, mark closed in one transaction; `AuditLog` captures prior state; `POST /bulk-actions/{auditLog}/undo` reverses within a 60s soft-undo window.
- **Settings consolidation** (`/settings`): Profile, Notifications, Sources, Triage, Account, Data sub-pages; Notifications/Triage/Data read and write the `Setting` model. Password, two-factor, sessions, and calendar feed fold under the shell.
- **Data export** (`/settings/data`): queued job bundles SQL + JSON + per-entity CSV into a manifest archive, downloadable for 24h; `export_jobs` tracks lifecycle; `exports:prune` (daily) deletes expired bundles.
- **Backups**: `backup:run` (nightly `pg_dump --format=custom` to an S3-compatible disk), `backup:prune` (30d daily / 26w weekly / monthly-indefinite tiering), `backup:verify` (monthly `pg_restore --list`).
- **Health**: `/health` (auth) returns DB reachability, last finished scrape per source, queue depth, free disk.
- **New schema** (foundation): `settings`, `notification_log`, `export_jobs`. **New enums:** `NotificationKind`, `BulkAction`, `ExportFormat`, `ExportJobStatus`. No new `EventType` cases — bulk ops record to `AuditLog`.

### Phase 6 finalize (round 2)

- **Liveness probe** (`GET /up`, public, `LivenessController`): replaces Laravel's built-in `health: '/up'`. Returns 200 only when the database is reachable AND a `ScrapeRun` finished within `config('health.liveness_max_scrape_age_hours', 48)`, else 503 with a terse machine reason. `bootstrap/app.php` no longer wires the framework default.
- **Audit-log viewer** (`GET /admin/audit`, auth, `Admin\Audit\IndexController`): paginated, prefix/outcome/date-range filtered viewer over `AuditLog`, scoped to the current tenant plus null-tenant rows (failed logins, lockouts). Surfaced in primary nav as "Audit".
- **Accessibility**: list keyboard-nav hook (`useListKeyboardNav`), axe checks (`tests/a11y/axe.spec.ts`).
- **Responsive**: horizontal-scroll data-table wrapper (`data-table-scroll`), viewport checks (`tests/responsive/viewport.spec.ts`).
- **Docs**: `CHANGELOG.md`, `PHASE_6_NOTES.md`, refreshed README phase table.

---

## Phase 5 — Pipeline + Outreach

**Ship criterion:** Run a job search end to end inside the app — track applications through a pipeline, manage contacts, send outreach from reusable templates, keep a calendar in sync — without a spreadsheet, CRM, or separate calendar.

- **Pipeline board** (`/pipeline`): status board across `PipelineStatus` cases with per-stage notes; add/detach roles; per-role application detail.
- **Offers**: record/revise offers (append-only `OfferRevision`), side-by-side comparison at `/pipeline/offers/comparison`.
- **Contacts CRM** (`/contacts`): full CRUD, optional company link, `last_touch` tracking, outreach log across `OutreachChannel`.
- **Templates** (`/templates`): reusable subject/body with hand-rolled `{{variable}}` substitution; compose against a contact.
- **Follow-up queue** (`/follow-ups`): contacts gone stale whose company has a role in an actively-waiting stage; one-click quick touch.
- **Calendar** (`/calendar`): CRUD with reminder lead-times; public token-keyed iCal feed (`/calendar/{token}/feed.ics`, hand-rolled RFC 5545, the only unauthenticated route); per-minute reminder sweep (`calendar:send-reminders`) queuing `CalendarEventReminderMail`.

---

## Phase 4 — Signals

**Ship criterion:** When I view a company, I have enough signal to decide whether to escalate it to A-tier without further research.

- **Funding signals**: yc-oss YC mirror sync, Google News funding scan (human-gated review queue).
- **Layoffs**: layoffs.fyi / Kaggle export sync + TrueUp fallback, cross-referenced onto companies.
- **GitHub org activity** poller (weekly, auth budget aware).
- **Engineering-blog RSS** poll folding cadence into the quality score.
- **Ghost-job scan** and **quality-score recompute** ordered last in the daily signal band.
- **ATS-switch detection**: companies flagged `needs_redetection` after 7 consecutive 404s, swept hourly to self-heal.
- **Daily company snapshots** (`CompanySnapshot`) as the substrate for trend charts.

---

## Phase 3 — Parser Polish

**Ship criterion:** When I correct a bad parse, the platform learns from it; I can build and save complex filter combinations.

- **In-app parse correction** with append-only `ParseCorrection` log; re-parse preserves manual corrections.
- **Fixture capture** from corrections to protect against regressions.
- **Parser confidence rollup** (`parser:rollup-confidence`) into `ParserConfidenceDaily` for per-source/field medians.
- **Saved views**: build and save complex filter combinations.

---

## Phase 2 — Aggregators + Triage

**Ship criterion:** I can review and process HN's monthly thread in 15 minutes flat.

- **Discovery sources**: HN "Who is Hiring" (monthly 3rd + 5th), Remotive, Himalayas, We Work Remotely, RemoteOK, and later tiers (Jobicy, Arbeitnow, Python.org, Django, Elixir Jobs, HexHire) — once daily.
- **Triage queue** (`/discover`): `AggregatorListing` rows promoted to the watch list or dismissed.
- **Auto-dismiss memory** (`DismissedCompany`) with normalized-name + dismiss-count surfacing.
- **90-day listing retention**, hard-delete on expiry.

---

## Phase 1 — ATS Pipeline

**Ship criterion:** Import my spreadsheet, add companies, watch them get scraped, see new roles appear, and find anything with Cmd-K.

- **ATS scrapers** (Greenhouse, Lever, Ashby, etc.) via Saloon connectors → structured `Role` rows.
- **Company CRUD** + CSV import wizard (idempotent on website domain).
- **Scrape orchestration** (`scrape:all`, `scrape:source`, per-company) with `ScrapeRun` summaries and 7-day `ScrapeRunArtifact` retention.
- **Cmd-K command palette** for fast navigation.
- **Bookmarks** with 30-day auto-expiry (`bookmarks:warn-expiring`, `bookmarks:expire`).

---

## Phase 0 — Foundation

**Ship criterion:** Log in, log out, change password, enable 2FA, see a placeholder dashboard. Deployed to Laravel Cloud, HTTPS, public-internet-safe.

- **Auth** via Fortify (email + password + TOTP 2FA, sessions), no public registration.
- **Hand-rolled row-level multi-tenancy**: `tenant_id` on every domain table, `BelongsToTenant` global scope + insert observer, `TenantContext` service, `ResolveTenantContext` middleware.
- **Schema baseline**, layout shell, Inertia + React + Tailwind v4 frontend.
- **CI green**, deployed to Laravel Cloud (EU) over HTTPS.

---

**Maintenance:** append a section when a phase ships. Do not edit a phase section after its phase closes; corrections go in the newest phase or in `PHASE_N_NOTES.md`.
