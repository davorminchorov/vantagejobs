# VantageJobs

A personal, single-user job-hunting platform. It watches a curated list of companies, polls their ATSes and discovery sources, parses postings into a structured schema, surfaces signals about the companies behind the roles, and gives me one place to run my watch list, applications, pipeline, and outreach.

**Built for me. Not a SaaS. Not for sale.** No public registration, no sharing, no LLMs.

The canonical documentation lives in [`docs/`](docs/). Start with [`docs/README.md`](docs/README.md) for the project overview, [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) for system design, [`docs/RUNBOOK.md`](docs/RUNBOOK.md) when something breaks, and [`docs/CHANGELOG.md`](docs/CHANGELOG.md) for per-phase shipped scope.

---

## Stack

- **Backend:** Laravel 13, PHP 8.5, FrankenPHP (classic mode in prod, Octane locally via Spin)
- **Database:** PostgreSQL 18
- **Frontend:** Inertia.js v2 + React 19 + TypeScript, Tailwind CSS v4 (teal/zinc/Geist), shadcn/ui
- **Auth:** Laravel Fortify (email + password + TOTP 2FA, passkeys, sessions), no public registration
- **Tenancy:** Hand-rolled row-level multi-tenancy (`tenant_id` on every domain table, `BelongsToTenant` global scope, `TenantContext` service). One active tenant in v1, schema-ready for more.
- **HTTP client:** Saloon wrapping Guzzle, typed Connectors/Requests per source
- **Mail:** Laravel Mail, transport chosen by `MAIL_MAILER` config (no provider SDK)
- **Backups:** Cloud-managed Postgres PITR + nightly off-platform `pg_dump` to an S3-compatible disk (B2/S3)
- **Hosting:** Laravel Cloud (EU region)
- **Local dev:** Spin (Docker Compose) wrapping `serversideup/php` images, served at `https://vantagejobs.test`

Every `php`, `artisan`, `composer`, `vendor/bin/*` (including `pint`), and test command runs through Spin — `spin exec -T php ...` when the stack is up, `spin run -T php ...` for a one-off. JS package manager is **pnpm**, never npm.

---

## Quick start

```bash
cp .env.example .env
spin up --build
spin run -T php composer install
spin run -T node pnpm install
spin exec -T php php artisan key:generate
spin exec -T php php artisan migrate
spin exec -T php php artisan tenant:create --name="Me" --slug=me
spin exec -T php php artisan db:seed
pnpm run build
```

Visit `https://vantagejobs.test`. See [`docs/README.md`](docs/README.md) for the full setup and common commands, and [`docs/RUNBOOK.md`](docs/RUNBOOK.md) for operations.

---

## Status

| Phase | Ship criterion | Status |
|---|---|---|
| 0 — Foundation | Auth, tenancy, schema, layout shell, CI all green | ✅ Shipped |
| 1 — ATS Pipeline | Scrape a tracked company's ATS into structured Roles, end to end | ✅ Shipped |
| 2 — Aggregators + Triage | Discovery sources feed a triage queue; promote leads to the watch list | ✅ Shipped |
| 3 — Parser Polish | Correct a bad parse in-app, save a fixture, re-parse without losing corrections | ✅ Shipped |
| 4 — Signals | Funding, layoffs, GitHub, blog, ghost-job, quality-score signals on companies | ✅ Shipped |
| 5 — Pipeline + Outreach | Run a search end to end: pipeline board, CRM, templates, calendar + iCal feed | ✅ Shipped |
| 6 — Polish + Notifications | Digests, A-tier alerts, weekly summary, source-health monitor, bulk ops + undo, settings, data export, backups | ✅ Shipped |

Per-phase detail: [`docs/CHANGELOG.md`](docs/CHANGELOG.md) and the `docs/PHASE_N_NOTES.md` files.

---

## License

Personal use. Not licensed for redistribution. No outside issues or pull requests.
