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

For the full scope and reasoning, see [`docs/PRD.md`](docs/PRD.md).

---

## Documentation

The four canonical docs live in `docs/`:

| Document | Purpose |
|---|---|
| [`PRD.md`](docs/PRD.md) | What the platform does and why. Source of truth for scope and decisions. |
| [`SOURCES.md`](docs/SOURCES.md) | How each data source is integrated — endpoints, fields, rate limits, gotchas. |
| [`ARCHITECTURE.md`](docs/ARCHITECTURE.md) | High-level system design, data flow, key models, key commands. |
| [`RUNBOOK.md`](docs/RUNBOOK.md) | Operational playbook. Read when something breaks. |

Phase-by-phase build history lives in `docs/PHASE_N_NOTES.md` files. Each is appended at the end of a phase, never edited after.

---

## Stack

- **Backend:** Laravel 13, PHP 8.5
- **Web server:** FrankenPHP (classic mode), with embedded Caddy for HTTPS
- **Database:** PostgreSQL 18
- **Frontend:** Inertia.js + React 19 + TypeScript
- **Styling:** Tailwind CSS v4 + shadcn/ui
- **Auth:** Laravel Fortify (email + password + TOTP 2FA), no public registration
- **HTTP client:** Guzzle with `Http::pool()` for concurrent fetches
- **Scheduling:** `php artisan schedule:run` from cron
- **Mail:** Postmark or Resend
- **Backups:** `pg_dump` nightly to Backblaze B2
- **Hosting:** Single VPS (Hetzner CX22, Germany region)

Why these choices: see [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) and [`docs/PRD.md`](docs/PRD.md) section 9.

---

## Quick start (local development)

### Prereqs

- PHP 8.5+
- Composer 2.7+
- Node 22+
- PostgreSQL 18+ (locally or via Docker)
- Git

### Setup

```bash
# Clone
git clone git@github.com:you/jobs.git
cd jobs

# Install dependencies
composer install
npm install

# Configure environment
cp .env.example .env
# Edit .env: set DB credentials, APP_URL, mail driver, etc.

# Generate app key
php artisan key:generate

# Create the database
createdb jobs

# Run migrations
php artisan migrate

# Seed the first user (uses INITIAL_USER_EMAIL and INITIAL_USER_PASSWORD from .env)
php artisan db:seed --class=CreateAdminUserSeeder

# Seed example companies (for local development)
php artisan db:seed --class=ExampleCompaniesSeeder
```

### Run dev servers

```bash
# Terminal 1 — Laravel
php artisan serve

# Terminal 2 — Vite (Inertia React build watcher)
npm run dev
```

Visit `http://localhost:8000` and log in with your seeded credentials.

### Common commands

```bash
# Run the full scrape (all sources)
php artisan scrape:all

# Scrape just one source
php artisan scrape:source greenhouse

# Scrape one company
php artisan scrape:source greenhouse --company=acme

# Re-parse all aggregator listings (preserves manual corrections)
php artisan parser:reparse-all

# Run the daily digest manually
php artisan digest:send

# Open the database console
php artisan db

# Run tests
php artisan test

# Type-check the frontend
npm run typecheck

# Lint
npm run lint
```

---

## Production deployment

See [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) for the full deployment story. TL;DR:

1. Provision a VPS (Hetzner CX22 or equivalent, Germany region recommended for GDPR).
2. Install PHP 8.5, PostgreSQL 18, FrankenPHP, Node 22.
3. Clone repo, copy `.env.production`, set secrets.
4. Run migrations + seeders for the initial user.
5. Configure FrankenPHP as a systemd service. Caddy will fetch TLS certs automatically.
6. Install the cron entry for `php artisan schedule:run`.
7. Configure nightly backup via the included `backup:run` artisan command.

For step-by-step recovery procedures, see [`docs/RUNBOOK.md`](docs/RUNBOOK.md).

---

## Operating principles

These are the rules this codebase is built around. They're enforced by code review (with myself) and PRD section 12.

1. **One user. Forever.** No multi-user. No team accounts. No public registration.
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
| 0 — Foundation | ⬜ Not started |
| 1 — ATS Pipeline | ⬜ Not started |
| 2 — Aggregators + Triage | ⬜ Not started |
| 3 — Parser Polish | ⬜ Not started |
| 4 — Signals | ⬜ Not started |
| 5 — Pipeline + Outreach | ⬜ Not started |
| 6 — Polish + Notifications | ⬜ Not started |

Update the boxes as phases ship. ⬜ → 🟨 (in progress) → ✅ (shipped).

---

## Acknowledgments

The deep research that powered the source list and parser design is preserved in [`docs/sourcing-map.html`](docs/sourcing-map.html). The PRD was iteratively refined across many sessions of planning. The Phase 6 closure ("ship it and use it") is the lesson, not the codebase.
