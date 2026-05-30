# Jobs

A personal, single-user job-hunting platform. Ingests job postings from ATS APIs and aggregator sources, parses them, surfaces signals about the companies behind them, and gives me one place to manage my watch list, applications, and pipeline.

**Built for me. Not a SaaS. Not for sale.**

---

## What this is

A long-running tool that quietly watches a curated list of ~200–500 companies, polls their ATSes every 6–24h, ingests new roles, parses them into a structured schema, and emails me when anything I care about changes. It also pulls discovery sources (HN "Who is Hiring", Remotive, Himalayas, We Work Remotely, RemoteOK) into a triage queue so promising leads don't slip past.

Targeted at senior and staff backend / fullstack remote roles with an EU/CET geographic bias.

## What this isn't

- Not a job board.
- Not a SaaS or product. Single user. No registration. No sharing.
- Not a recruiter tool.
- Not AI-powered. No LLMs anywhere. Heuristic parsing only.
- Not an auto-apply tool. Not a resume builder.
- Not a "find your dream job in 30 days" gimmick.

For the full scope and reasoning, see [`docs/prd-v2.md`](prd-v2.md).

---

## Documentation

The four canonical docs live in `docs/`:

| Document | Purpose |
|---|---|
| [`prd-v2.md`](prd-v2.md) | What the platform does and why. Source of truth for scope and decisions. |
| [`CHANGELOG.md`](CHANGELOG.md) | Per-phase shipped scope, phases 0–6. |
| [`SOURCES.md`](SOURCES.md) | How each data source is integrated — endpoints, fields, rate limits, gotchas. |
| [`ARCHITECTURE.md`](docs/ARCHITECTURE.md) | High-level system design, data flow, key models, key commands. |
| [`RUNBOOK.md`](docs/RUNBOOK.md) | Operational playbook. Read when something breaks. |

Phase-by-phase build history lives in `docs/PHASE_N_NOTES.md` files. Each is appended at the end of a phase, never edited after.

---

## Stack

- **Backend:** Laravel 13, PHP 8.5
- **Web server:** FrankenPHP — classic mode in production (Cloud-managed); Octane (worker mode) locally via Spin, as scaffolded by the Spin Pro template
- **Database:** PostgreSQL 18 (Cloud-managed in prod, Spin service locally)
- **Frontend:** Inertia.js + React 19 + TypeScript
- **Styling:** Tailwind CSS v4 + shadcn/ui
- **Auth:** Laravel Fortify (email + password + TOTP 2FA), no public registration
- **Tenancy:** Hand-rolled row-level multi-tenancy (`tenant_id` on every domain table, `BelongsToTenant` global scope, `TenantContext` service). Single active Tenant in v1, schema-ready for more.
- **HTTP client:** Saloon (`saloonphp/saloon`) wrapping Guzzle, with typed Connectors/Requests per source and Saloon's pool API for concurrency
- **Scheduling:** `php artisan schedule:run` invoked by a Laravel Cloud scheduled task every minute (no host cron)
- **Mail:** Laravel Mail, transport chosen by `MAIL_MAILER` config at deploy time (no provider SDK, no hardcoded Postmark/Resend dependency)
- **Backups:** Laravel Cloud managed Postgres PITR + nightly off-platform `pg_dump` to an S3-compatible disk (B2/S3)
- **Hosting:** Laravel Cloud (EU region)
- **Local dev:** Spin (Docker Compose) wrapping `serversideup/php` images, served at `https://vantagejobs.test`

Why these choices: see [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) and [`docs/prd-v2.md`](prd-v2.md) section 9.

---

## Quick start (local development)

Local development runs entirely through Spin (Docker Compose). You don't install PHP, Composer, Node, or Postgres on the host.

### Prereqs

- Docker Desktop (or compatible Docker engine + Compose v2)
- Git
- An entry in `/etc/hosts` pointing `vantagejobs.test` to `127.0.0.1`

### Setup

```bash
# Clone
git clone git@github.com:you/vantagejobs.git
cd vantagejobs

# Configure environment
cp .env.example .env
# Edit .env: set INITIAL_TENANT_NAME, INITIAL_USER_EMAIL, INITIAL_USER_PASSWORD, mail driver, etc.

# Start the stack (FrankenPHP + Node + Postgres)
spin up --build

# In another terminal: install dependencies inside the container
spin run -T php composer install
spin run -T node pnpm install

# Generate app key, migrate, seed
spin exec -T php php artisan key:generate
spin exec -T php php artisan migrate
spin exec -T php php artisan db:seed --class=CreateInitialTenantSeeder
spin exec -T php php artisan db:seed --class=CreateAdminUserSeeder
spin exec -T php php artisan db:seed --class=ExampleCompaniesSeeder
```

Visit `https://vantagejobs.test` and log in with your seeded credentials.

### Common commands

All commands run through Spin. Use `spin exec -T php ...` when the stack is up; use `spin run -T php ...` for one-off containers when it's down.

```bash
# Full scrape (all companies/sources for a tenant)
spin exec -T php php artisan scrape:all --tenant=me

# One company (by id)
spin exec -T php php artisan scrape:company {id} --tenant=me

# Re-parse all aggregator listings (preserves manual corrections)
spin exec -T php php artisan parser:reparse-all --tenant=me

# Run the daily digest manually (--hour bypasses the local send-time gate)
spin exec -T php php artisan digest:send-daily --tenant=me --hour={current-hour}

# Open a database shell
spin exec -T postgres psql -U vantagejobs vantagejobs

# Run the test suite
spin exec -T php php artisan test --compact --parallel --recreate-databases

# Format dirty PHP
spin run -T php ./vendor/bin/pint --dirty --format agent

# Type-check the frontend
spin exec -T node pnpm run typecheck

# Lint
spin exec -T node pnpm run lint
```

See `docs/ARCHITECTURE.md` for the broader development workflow and the `spin-laravel-development` skill for in-depth Docker/Spin tips.

---

## Production deployment

Production runs on Laravel Cloud (EU region). See [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) for the full picture. TL;DR:

1. Create a Laravel Cloud project, link this Git repository, choose the EU region.
2. Create a managed PostgreSQL 18 instance and link it to the project.
3. Set production secrets via `cloud env:set` (APP_KEY, INITIAL_TENANT_*, INITIAL_USER_*, mail driver keys, B2 keys, GitHub token).
4. Attach the custom domain via `cloud domains:add` and update DNS at the registrar.
5. `git push origin main` — Cloud builds (`composer install`, `pnpm install --frozen-lockfile && pnpm run build`, `php artisan migrate --force`, config/route/view caching) and deploys.
6. Register a Cloud scheduled task that runs `php artisan schedule:run` every minute.
7. Confirm the `backup:run` schedule pushes nightly `pg_dump` to Backblaze B2 as off-platform redundancy alongside Cloud's managed Postgres PITR.

For step-by-step recovery procedures, see [`docs/RUNBOOK.md`](docs/RUNBOOK.md). For Cloud-specific operations (env vars, deploys, rollbacks, shell access), see the `deploying-laravel-cloud` skill.

---

## Operating principles

These are the rules this codebase is built around. They're enforced by code review (with myself) and PRD section 12.

1. **One active tenant in v1.** No public registration, no sharing UI, no team features. The schema is tenant-aware (every domain row has `tenant_id`) so a future second tenant is a seeder change, not a rewrite. Operationally, it's still just me.
2. **No LLMs. Ever.** Heuristic parsing only. Determinism and testability are the whole point.
3. **The data is mine.** Full SQL/JSON/CSV export available at any time. No vendor lock-in.
4. **Boring tech wins.** Stable, well-documented, widely-used. Cutting-edge only when materially better.
5. **Every phase is shippable on its own.** No half-built features in main.
6. **Honest signal over noise.** When the parser is uncertain, it says so. When a source is down, the dashboard says so. No silent failure.
7. **Self-discipline is the bottleneck, not technology.** The platform helps me run a workflow; it doesn't run my career.

---

## License

Personal use. Not licensed for redistribution.

If you happen to find this repo and want to build your own version, that's fine — fork it and have at it. But this isn't a project I'm planning to maintain as open source, and I don't accept issues or pull requests from outside.

---

## Status

| Phase | Status |
|---|---|
| 0 — Foundation | ✅ Shipped |
| 1 — ATS Pipeline | ✅ Shipped |
| 2 — Aggregators + Triage | ✅ Shipped |
| 3 — Parser Polish | ✅ Shipped |
| 4 — Signals | ✅ Shipped |
| 5 — Pipeline + Outreach | ✅ Shipped |
| 6 — Polish + Notifications | ✅ Shipped |

Update the boxes as phases ship. ⬜ → 🟨 (in progress) → ✅ (shipped). Per-phase shipped scope: [`CHANGELOG.md`](CHANGELOG.md) and the `PHASE_N_NOTES.md` files.

---

## Acknowledgments

The deep research that powered the source list and parser design is preserved in [`docs/sourcing-map.html`](docs/sourcing-map.html). The PRD was iteratively refined across many sessions of planning. The Phase 6 closure ("ship it and use it") is the lesson, not the codebase.
