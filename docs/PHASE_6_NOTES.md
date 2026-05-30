# Phase 6 Notes

**Date:** May 2026
**Ship criterion:** Live with the tool. Notifications (daily digest, A-tier instant alerts, weekly summary, source-health monitor) keep me touching it; a consolidated `/settings` shell lets me tune it; bulk operations with a 60s undo make triage fast; my data is fully exportable; and off-platform backups are real, retained, and verified.
**Status:** Met. The round-2 finalize slice closed the previously-deferred `/up` liveness contract and added an audit-log viewer plus accessibility/responsive polish (see Finalize).

---

## What shipped

### Notifications

- **Daily digest.** `digest:send-daily` (SendDailyDigestCommand) ticks hourly and self-gates per recipient on their local configured send hour (`config('notifications.digest_send_time', '07:00')`, recipient timezone via `User.timezone`). Skips empty days by default (`digest_skip_empty` Setting, default ON). `--tenant=` limits the sweep; `--hour=` overrides the gate for manual re-fires/tests.
- **A-tier instant alerts.** `notifications:send-a-tier-alerts` (SendATierAlertsCommand) runs every 15 minutes, finds high-signal new roles on A-tier companies, and throttles to **1 email per company per hour** via a `notification_log` row check (`a_tier_throttle_minutes`, default 60). A DND-window hit (`dnd_start`/`dnd_end`, default 22:00–07:00) **defers** the alert to the next allowed minute rather than dropping it. `--lookback=` overrides the new-role window.
- **Weekly summary.** `notifications:send-weekly-summary` (SendWeeklySummaryCommand) is per-tenant and day-gated inside the command (`weekly_summary_day`, default Sunday). The schedule runs it daily at the configured send time so the gated day lands; the per-week gate makes the daily-but-day-gated tick safe.
- **Source-health monitor.** `monitor:source-health` (MonitorSourceHealthCommand) sweeps hourly across tenants, reusing `AggregatorSource.consecutive_failures` / `last_run_at` / `last_error` plus `ParserConfidenceDaily` week-over-week median math. It emails one deduped fix-me alert per red source (dedupe through `notification_log`). Thresholds in `config/sources.php` (`consecutive_failure_alert` 3, `confidence_drop_wow_pct` 20).
- **Email transport.** Every notification is a queued Markdown Laravel Mailable; the transport is `MAIL_MAILER`-configured at deploy time. No Postmark/Resend SDK, no new dependency.

### Settings

- **Consolidated `/settings` shell.** `GET /settings` redirects to `/settings/profile`. Sub-pages: Profile, Calendar feed, Notifications, Triage, Sources (links to `/discover/sources`), Account (Password, Two-factor, Active sessions), Data. The new read/write pages are Notifications (`NotificationsController` + `UpdateNotificationsController`), Triage (`TriageController` + `UpdateTriageController`), and Data (`DataController`). They read and write the `Setting` key-value model through a typed accessor that layers DB rows over `config(...)` defaults.

### Bulk operations + undo

- **Bulk action.** `POST /bulk-actions` (PerformBulkActionController, validated by `Bulk\PerformBulkActionRequest`) performs one `BulkAction` (change_tier, tag, untag, dismiss, bookmark, mark_closed) over selected rows in a single `DB::transaction`, executed by `app/Bulk/BulkActionExecutor.php`, and writes an `AuditLog` row capturing prior state.
- **Undo.** `POST /bulk-actions/{auditLog}/undo` (UndoBulkActionController, `whereNumber('auditLog')`) reads the captured before-state and reverses it within a 60-second soft-undo window; the client toast triggers it. `AuditLog` is the primary record so **no new `EventType` case was added**.

### Data export

- **Request + lifecycle.** `POST /settings/data/export` (RequestExportController) queues a job that produces a SQL dump, JSON-normalized export, and per-entity CSV into a manifest-bundled archive on `config('export.disk', 'local')` at `config('export.bundle_path', 'exports')`. `export_jobs` tracks the row through `ExportJobStatus` (pending → running → completed/failed → expired); the manifest holds schema version + per-entity row counts + timestamp.
- **Download + prune.** `GET /settings/data/{export}/download` (DownloadExportController, `whereNumber('export')`) streams the bundle while inside the `config('export.download_ttl_hours', 24)` window. `exports:prune` (ExportsPruneCommand, daily) deletes expired bundles and marks rows `expired`.

### Backups + health

- **Backups.** `backup:run` (nightly `pg_dump --format=custom --no-owner --no-privileges` → `Storage::disk(config('backup.disk'))`), `backup:prune` (retention: 30 days daily / 26 weeks weekly / monthly indefinite), `backup:verify` (monthly `pg_restore --list`, alerts on empty TOC). Detail in `RUNBOOK.md#database-backups`.
- **Health.** `GET /health` (auth, `HealthController`) returns operator JSON: DB reachability (`getPdo()`), most-recent finished scrape per source (stale-flagged past `config('health.liveness_max_scrape_age_hours', 48)`), pending queue depth, free disk space.

### Foundation used by this phase

- **New models/tables:** `Setting` (`settings`), `NotificationLogEntry` (`notification_log`), `ExportJob` (`export_jobs`) — all tenant-scoped.
- **New enums:** `NotificationKind`, `BulkAction`, `ExportFormat`, `ExportJobStatus`.
- **New config:** `notifications.php`, `sources.php` (source-health thresholds), `export.php`, `backup.php`, `health.php`.

---

## Integration / wiring done in this phase

- **`routes/web.php`:** added `/bulk-actions` + `/bulk-actions/{auditLog}/undo`, `/health`, and the `settings.notifications`, `settings.triage`, `settings.data`, `settings.data.export`, `settings.data.download` routes inside the existing `settings.` prefix/name group. Id params use `whereNumber`.
- **`routes/console.php`:** added the Phase 6 schedule band — `digest:send-daily` (hourly), `notifications:send-a-tier-alerts` (every 15 min), `notifications:send-weekly-summary` (daily, day-gated, per-tenant loop), `monitor:source-health` (hourly), `exports:prune` (daily 06:10), `backup:run` (01:30), `backup:prune` (02:00), `backup:verify` (monthly 1st 02:15). Notification sweeps run tenant-agnostic and self-gate per recipient; `withoutOverlapping()` on the recurring ones.
- **`resources/js/Layouts/SettingsLayout.tsx`:** added the Notifications, Triage, Sources, and Data nav entries grouped under Notifications/Account/Data sections.
- **`EventType`:** no changes — bulk operations record to `AuditLog`, not the domain `Event` stream.

> The integration above was performed by prior Phase 6 slices and is already committed. This (documentation) slice changed only `docs/` and the root `README.md`.

---

## Deviations / decisions worth carrying forward

- **The DB-gated `/up` liveness contract shipped in the round-2 finalize slice** (see Finalize below). `bootstrap/app.php` no longer wires `withRouting(health: '/up')`; the public `LivenessController` at `GET /up` now owns the contract.
- **Email stays provider-agnostic.** All four notification kinds are queued Markdown Mailables; transport is `MAIL_MAILER` config, mirroring Phase 5's `CalendarEventReminderMail`. No SDK lock-in.
- **A-tier DND defers, never drops.** A blocked alert is re-scheduled for the next allowed minute. The 1/company/hour throttle is enforced by reading `notification_log`, so it survives process restarts.
- **Undo is a 60s soft window backed by `AuditLog`.** The before-state lives in the `AuditLog` context column; undo reverses it transactionally. After 60s the undo route should refuse. Reversible actions: change tier, tag/untag, dismiss, bookmark, mark closed.
- **Settings defaults live in config; DB rows override.** A missing `Setting` row never breaks behavior — the accessor falls back to `config(...)`. Notification recipient defaults to the user's login email when unset.
- **Backups are whole-database, not tenant-scoped.** One `pg_dump` captures every tenant. Restore from the custom-format dump uses `pg_restore`, not `psql <`. Retention buckets use ISO weeks and calendar months.

---

## Finalize (round 2)

Closed the deferred liveness item and added the remaining v1 polish.

- **Liveness `/up`.** `App\Http\Controllers\LivenessController` (public, unauthenticated) at `GET /up`. Returns `{"status":"up"}` (200) only when a PDO connection opens AND a `ScrapeRun` finished within `config('health.liveness_max_scrape_age_hours', 48)`, else `{"status":"down","reason":...}` (503). The scrape check spans all tenants (`ScrapeRun::withoutTenantScope()`) since liveness is fleet-wide. `bootstrap/app.php` no longer registers the framework default health route.
- **Audit-log viewer.** `App\Http\Controllers\Admin\Audit\IndexController` at `GET /admin/audit` (auth). Paginated (50/page), filtered by action prefix / outcome / date range via `AuditLogListFilters` (whitelisted, defensively parsed). Scopes to the current tenant **plus** null-tenant rows (failed logins fire before tenant context resolves). Context never leaks passwords. Surfaced in primary nav as "Audit".
- **Accessibility.** `resources/js/hooks/useListKeyboardNav.ts` for keyboard list traversal; `tests/a11y/axe.spec.ts` axe checks.
- **Responsive.** `resources/js/components/ui/data-table-scroll.tsx` horizontal-scroll wrapper; `tests/responsive/viewport.spec.ts` viewport checks.

### Wiring done in finalize

- **`routes/web.php`:** public `GET /up` → `LivenessController`; `GET /admin/audit` → `Admin\Audit\IndexController` inside the existing `admin.` prefix/name group (`admin.audit.index`).
- **`resources/js/Layouts/AuthenticatedLayout.tsx`:** added the "Audit" nav entry after "Runs".

---

## Verification

The finalize slice ran the full gate inside `spin exec`: `pint --dirty` clean, `php artisan test --compact` (1097 passed / 4142 assertions), `pnpm run build` (no TS/JSX errors). Earlier Phase 6 documentation claims were checked against live source: route names in `routes/web.php`, schedule entries in `routes/console.php`, command signatures under `app/Console/Commands/`, controllers under `app/Http/Controllers/{Bulk,Settings}/`, the `Setting`/`NotificationLogEntry`/`ExportJob` models, and the `/up` wiring in `bootstrap/app.php`.

---

## Docs changed in this slice

- `README.md` — replaced the stock Laravel readme with a project overview + phase status table.
- `docs/README.md` — flipped the phase status table to shipped; corrected the Mail line (MAIL_MAILER-driven, no SDK) and the backup target (S3-compatible disk).
- `docs/ARCHITECTURE.md` — added `NotificationLogEntry`/`ExportJob`/`SettingsRepository`, a Notifications-and-monitoring section, bulk-ops + undo, data export + health, and corrected the scheduler location to `routes/console.php`.
- `docs/RUNBOOK.md` — already carried the Phase 6 backup section; verified commands run under `spin exec`/`spin run`.
- `docs/CHANGELOG.md` — new file, one section per phase (0–6).
- `docs/PHASE_6_NOTES.md` — this file.
- `docs/prd-v2.md` — §11.2 marks the public read-only API decision resolved post-Phase-6; §11.3 stays empty.
