# VantageJobs

A personal job-hunting app that runs on your own computer or server. It watches the companies you care about, collects new job postings from them and from public job boards, and gives you one place to sort through postings, track applications and keep in touch with people.

**Built for one person. Not a SaaS. Not for sale.** There is no sign-up page, no sharing and no AI. Postings are parsed with plain rules, not language models.

---

## What it does

- **Watches companies.** You keep a list of companies. VantageJobs checks their careers pages every 6 to 24 hours on the hiring systems it supports (Greenhouse, Lever, Ashby, Workable, SmartRecruiters, Personio, Recruitee, Teamtailor) and records every new or closed role.
- **Pulls in job boards.** It also reads 15 public sources, including Hacker News "Who is Hiring", Remotive, Himalayas, We Work Remotely, RemoteOK, Arbeitnow, Working Nomads, Jobicy and LaraJobs. Those postings land in a triage queue called **Discover**.
- **Discover.** A list of new postings next to the full description of the selected one. Keep, bookmark, snooze or dismiss each posting with a click or a single key. You can filter by source, seniority, region, whether pay is listed and remote-only, and dismiss every listing from one company at once.
- **Reads postings for you.** Each posting is turned into structured fields: company, role, seniority, pay range, remote policy, region and tech stack. If the parser gets a field wrong, you can fix it in place and the fix sticks.
- **Company signals.** Funding news, layoffs, GitHub and blog activity, and roles that stay open suspiciously long ("ghost jobs") are attached to each company.
- **Pipeline and contacts.** A board for your applications, a contact list, follow-up reminders, message templates and a calendar you can subscribe to from your calendar app.
- **Insights.** Hiring trends, pay ranges and companies that recently raised money.
- **Email.** A daily digest, alerts for your top-tier companies, a weekly summary and warnings when a job board or a company's hiring system stops responding.

---

## Installing it on your computer

Everything runs inside Docker, so you don't need to install PHP, a database or Node.js yourself. The project uses a small tool called [Spin](https://serversideup.net/open-source/spin/) that starts and stops the Docker containers for you.

These steps are for macOS and Linux. On Windows, run them inside [WSL 2](https://learn.microsoft.com/windows/wsl/install).

### 1. Install the tools

- [Docker Desktop](https://www.docker.com/products/docker-desktop/) (or Docker Engine with Compose v2 on Linux). Start it before you continue.
- [Git](https://git-scm.com/downloads)
- Spin:

  ```bash
  bash -c "$(curl -fsSL https://raw.githubusercontent.com/serversideup/spin/main/tools/install.sh)"
  ```

  Open a new terminal afterwards and check that `spin --version` prints a version.

### 2. Download the code

```bash
git clone https://github.com/davorminchorov/vantagejobs.git
cd vantagejobs
```

### 3. Point the local web addresses at your computer

The app is served at `https://vantagejobs.test`. Two helper addresses are also used: `vite.dev.test` serves the page's scripts and styles, and `mailpit.dev.test` shows the emails the app sends. Add all three to your hosts file:

```bash
echo "127.0.0.1 vantagejobs.test vite.dev.test mailpit.dev.test" | sudo tee -a /etc/hosts
```

### 4. Trust the local HTTPS certificate

The project includes a self-signed certificate for these addresses. Your browser needs to trust it, or the page will load blank because its scripts are blocked.

On macOS:

```bash
sudo security add-trusted-cert -d -r trustRoot -k /Library/Keychains/System.keychain .infrastructure/conf/traefik/dev/certificates/local-ca.pem
```

On Linux, import `.infrastructure/conf/traefik/dev/certificates/local-ca.pem` as a certificate authority in your browser's certificate settings. As a quick alternative on any system, open both `https://vite.dev.test` and `https://vantagejobs.test` once and accept the browser warning.

### 5. Create your settings file

```bash
cp .env.example .env
```

Open `.env` in a text editor and set your login details:

```
INITIAL_USER_NAME=Your Name
INITIAL_USER_EMAIL=you@example.com
INITIAL_USER_PASSWORD=choose-a-password
INITIAL_TENANT_TIMEZONE=Europe/Berlin
```

If you leave `INITIAL_USER_PASSWORD` empty, a random password is generated and printed once in step 7.

### 6. Install dependencies and start the app

```bash
spin run -T php composer install
spin run -T node pnpm install
spin up
```

The first run downloads and builds the Docker images, which takes a few minutes. `spin up` keeps running and shows logs. Leave it open and use a second terminal, in the same folder, for the next step.

### 7. Set up the database

```bash
spin exec -T php php artisan key:generate
spin exec -T php php artisan migrate
spin exec -T php php artisan db:seed
```

The last command creates your account, a few example companies and the job board sources.

### 8. Open the app

Go to **https://vantagejobs.test** and log in with the email and password from step 5.

Background workers (scrapers, emails, scheduled checks) start automatically with `spin up`. To fetch postings right away instead of waiting for the schedule:

```bash
spin exec -T php php artisan aggregator:run-all --tenant=default
spin exec -T php php artisan scrape:all --tenant=default
```

The first command reads the job boards and the second checks your watched companies. `default` is the `INITIAL_TENANT_SLUG` value from `.env`.

Emails sent by the app don't leave your computer during local use. You can read them at **https://mailpit.dev.test**.

---

## Everyday use

| Task | Command |
|---|---|
| Start the app | `spin up` |
| Stop the app | Press `Ctrl+C` in the `spin up` terminal, or run `spin down` |
| Update to the latest code | `git pull`, then `spin run -T php composer install`, `spin run -T node pnpm install`, `spin up`, and in a second terminal `spin exec -T php php artisan migrate` |
| Reset a forgotten password | `spin exec -T php php artisan user:reset-password` |

## When something goes wrong

- **The page is blank.** The browser doesn't trust the certificate for `vite.dev.test`. Repeat step 4, or open `https://vite.dev.test` and accept the warning.
- **"This site can't be reached".** Check that `spin up` is running and that the hosts entry from step 3 exists.
- **`spin up` fails because port 80 or 443 is in use.** Another web server is running on your computer (for example Laravel Herd, Valet, MAMP or another Docker project). Stop it and run `spin up` again.
- **A change in `.env` has no effect.** Stop the app and start it again. The app server keeps the old settings in memory until it restarts.

For operations and recovery steps, see [`docs/RUNBOOK.md`](docs/RUNBOOK.md).

---

## For developers

- **Backend:** Laravel 13 on PHP 8.5, served by FrankenPHP (Octane locally), PostgreSQL 18, Horizon for queues, Saloon for HTTP sources, Fortify for auth (password, TOTP 2FA, passkeys).
- **Frontend:** Inertia.js with React 19 and TypeScript, Tailwind CSS v4, shadcn/ui-style components.
- **Tenancy:** row-level (`tenant_id` on every domain table, `BelongsToTenant` global scope). One tenant in practice.
- **Hosting:** Laravel Cloud (EU), with Postgres point-in-time recovery and nightly off-platform backups.

Run every PHP, Artisan, Composer and test command through Spin (`spin exec -T php ...` while the stack is up, `spin run -T php ...` when it is down). Use pnpm, never npm.

```bash
spin exec -T php php artisan test --compact --parallel --recreate-databases
spin exec -T php ./vendor/bin/pint --dirty --format agent
spin exec -T node pnpm typecheck
spin exec -T node pnpm lint
```

Octane keeps PHP code in memory, so backend changes need `spin exec -T php php artisan octane:reload --server=frankenphp` before they show up in the browser.

The full documentation lives in [`docs/`](docs/): [`docs/README.md`](docs/README.md) for the project overview, [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) for system design, [`docs/SOURCES.md`](docs/SOURCES.md) for the supported sources, [`docs/RUNBOOK.md`](docs/RUNBOOK.md) for operations and [`docs/CHANGELOG.md`](docs/CHANGELOG.md) for what shipped in each phase.

---

## License

Personal use. Not licensed for redistribution. No outside issues or pull requests.
