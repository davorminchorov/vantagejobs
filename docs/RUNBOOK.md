# Runbook

**Purpose:** When something is broken, this is the first place you look. Each section answers a specific "X is wrong, what do I do?" question. Terse, actionable, no fluff.

**Audience:** Future-you, 6 months from now, half-remembering how this thing works at 11pm on a Sunday.

**Format:** Every section starts with the symptom (what you'd see) and ends with what to do. Commands are copy-pastable.

**Maintenance:** When you fix a problem that took more than 10 minutes to figure out, add a section here.

---

## Quick index

| Symptom | Section |
|---|---|
| I can't log in | [Auth recovery](#auth-recovery) |
| Scraper for source X is failing | [Scraper troubleshooting](#scraper-troubleshooting) |
| Parser is producing bad output | [Parser debugging](#parser-debugging) |
| Daily digest email didn't arrive | [Notification debugging](#notification-debugging) |
| Database is full / running slow | [Database health](#database-health) |
| Need to restore from backup | [Backup restore](#backup-restore) |
| Need to add a new source | [Adding a new source](#adding-a-new-source) |
| Need to add a new tracked company | [Adding a company](#adding-a-company) |
| Site is down | [Outage response](#outage-response) |
| Deployment broke something | [Rollback](#rollback) |
| Need to rotate a secret | [Secret rotation](#secret-rotation) |
| GitHub API rate-limited | [GitHub API issues](#github-api-issues) |
| Triage queue is overwhelmed | [Triage queue overflow](#triage-queue-overflow) |
| Production runtime won't boot | [Cloud runtime troubleshooting](#cloud-runtime-troubleshooting) |
| Scheduled tasks aren't running | [Scheduler debugging](#scheduler-debugging) |
| Local Spin stack misbehaving | [Local dev troubleshooting](#local-dev-troubleshooting) |
| Suspect a tenant_id leak | [Tenancy invariants](#tenancy-invariants) |

---

## Auth recovery

### Locked out of the only account

**Symptom:** Login attempts fail. Password reset email doesn't arrive, or I forgot the email.

**Recovery via Laravel Cloud shell:**

```bash
cloud shell --env=production
php artisan user:reset-password
```

This prompts for the new password and writes it directly. Logs the action to `AuditLog`.

Locally, run the same command via Spin:

```bash
spin exec -T php php artisan user:reset-password
```

**If 2FA is the problem (lost authenticator):**

```bash
cloud shell --env=production
php artisan user:disable-2fa
```

Disables 2FA on the single account. Next login will skip the TOTP prompt. Re-enable 2FA from settings after logging back in.

### Recovery codes lost

You generated recovery codes when enabling 2FA but lost them. If you still have authenticator access, just use it and regenerate codes from `/settings/account`. If you've lost both, use `php artisan user:disable-2fa` and re-enroll.

### Suspicious login activity

Check `/admin/audit` for recent login events with unfamiliar IPs. To kill all sessions globally:

```bash
php artisan session:flush
```

Then rotate `APP_KEY` (see [Secret rotation](#secret-rotation)) and reset your password.

---

## Scraper troubleshooting

### A single source is failing

**Symptom:** Source health panel shows red. `/admin/runs` shows consecutive errors for one source.

**Step 1:** Identify the failure pattern.

```bash
cloud shell --env=production
php artisan tinker
>>> App\Models\ScrapeRun::latest()->where('source', 'greenhouse')->take(5)->get(['status', 'error_message', 'started_at']);
```

Local equivalent: `spin exec -T php php artisan tinker`.

**Step 2:** Inspect a raw artifact (within 7-day retention window).

```bash
cloud shell --env=production
php artisan tinker
>>> App\Models\ScrapeRunArtifact::where('source', 'greenhouse')->latest()->first();
```

Artifacts live in `storage/scrape-artifacts/` on the running container; they reset on each Cloud release, so persistent debugging uses the DB-backed `ScrapeRunArtifact` records.

If the artifact has unexpected JSON shape or HTML where JSON should be, the upstream API changed. Update the scraper or parser accordingly.

**Step 3:** Run the scraper manually with verbose output.

```bash
# Production
cloud shell --env=production
php artisan scrape:source greenhouse --company={slug} --verbose

# Local
spin exec -T php php artisan scrape:source greenhouse --company={slug} --verbose
```

**Step 4:** Common causes by source:

- **Greenhouse:** `404` likely means the board_token changed. Check the company's careers page. Update `companies.ats_slug`.
- **Lever:** Same as Greenhouse. Check the slug in the careers URL.
- **Ashby:** Same.
- **HN Algolia:** Index lag (a few hours occasionally) is normal. Rate-limited responses are rare; retry. If persistently failing, check Algolia status page.
- **Remotive:** Hit rate limit. Wait an hour. Verify polling cadence is daily, not hourly.
- **Himalayas:** 429 means you're polling too often. Daily is correct.
- **WWR RSS:** Feed URL changed. Check `https://weworkremotely.com/categories` for the new URL.
- **layoffs.fyi:** Airtable embed URL rotated. Update the scraper's URL or fall back to the Kaggle mirror.

### All scrapers failing simultaneously

**Likely causes:**
- Laravel Cloud incident affecting outbound network. Check Cloud's status page first.
- DNS issue. From `cloud shell`: `getent hosts api.lever.co`.
- Cloud Postgres connectivity. `cloud shell` → `php artisan tinker` → `DB::connection()->getPdo();`.
- Filesystem near full on the compute instance (rare; ephemeral on Cloud). Check via Cloud's metrics panel.
- Tenant context not resolved inside the scheduler. Check that the scheduled command sets `TenantContext` before doing tenant-scoped work.

### A company appears to be "stuck"

**Symptom:** `last_checked_at` on a Company hasn't updated in days, but the source itself is healthy.

**Likely causes:**
- The Company is in graveyard status (intentional — graveyard companies aren't polled).
- The Company's ATS slug returns 404 and the 7-day re-detection grace period is running.

Check `companies.ats_status`. If `needs_redetection`, run:

```bash
php artisan ats:redetect --company-id={id}
```

---

## Parser debugging

### A specific listing has obviously wrong parsed fields

**Use the correction UI first.** That's literally what it's for. Open the listing in `/discover/{id}`, click "correct this" on the wrong field, save, optionally save as a fixture.

If the same parse error appears across many listings, treat it as a parser bug.

### Parser confidence is dropping for a specific source

**Symptom:** `/admin/parser` shows median confidence for a source dropping > 20% week-over-week.

**Step 1:** Look at the corrections you've made for that source recently.

```bash
php artisan tinker
>>> App\Models\ParseCorrection::whereHas('listing', fn($q) => $q->where('source', 'hn_hiring'))->latest()->take(20)->get();
```

Pattern in the corrections → parser bug to fix.

**Step 2:** Run the parser against recent fixtures.

```bash
php artisan test --filter=ParserTest
```

If a fixture fails, the parser regressed against known-good data. Bisect with `git bisect`.

**Step 3:** If the source format changed, you need to update the parser. Use the artifact retention to look at recent raw input:

```bash
php artisan parser:debug --listing-id={id}
```

This re-runs the parser against the raw input verbosely, showing each extractor's output and confidence.

### Stack tag is over-matching (e.g., "Go" matching unrelated text)

Update `dictionaries/stack-tags.php`. For ambiguous short tags (Go, R, C, D), require word boundaries plus context cues:

```php
// dictionaries/stack-tags.php
[
    'canonical' => 'go',
    'aliases' => ['go', 'golang'],
    'contextCue' => '/\b(?:written in|developer|engineer|language|programmer)\b/i',
],
```

Re-parse to apply:

```bash
php artisan parser:reparse --source=hn_hiring
```

---

## Notification debugging

### Daily digest didn't arrive

**Symptom:** No digest email this morning.

**Step 1:** Check whether it was supposed to send.

```bash
php artisan tinker
>>> App\Mail\DailyDigest::wouldSendToday();
```

The digest is configured to skip empty days by default (PRD section 7.4 + Phase 6). If there were no new Events in the last 24h, it didn't send. This is correct behavior.

**Step 2:** If there was content, check the queue and mail logs.

```bash
php artisan queue:failed
tail -f storage/logs/laravel.log | grep -i mail
```

**Step 3:** Verify Postmark/Resend credentials.

```bash
php artisan tinker
>>> Mail::raw('test', fn($m) => $m->to('you@example.com')->subject('test'));
```

If this fails, the mail driver is misconfigured. Check `.env` for `MAIL_MAILER`, `POSTMARK_TOKEN` / `RESEND_API_KEY`.

**Step 4:** Verify the schedule is running.

```bash
php artisan schedule:list
```

The digest should appear with its cron expression. If not, `app/Console/Kernel.php` doesn't have it registered.

### A-tier alert didn't fire for a new role

**Symptom:** A new role appeared on an A-tier Company but no email arrived.

**Likely causes:**
- The A-tier alert is throttled to 1 email per Company per hour (PRD Phase 6). Check if another alert went out for the same Company in the last hour.
- DND window. Default 10pm-7am CET. The alert is queued and will fire after the window ends.
- Mail driver error (see above).

Check the queue:

```bash
php artisan queue:work --once --queue=alerts
```

---

## Database health

### Database is slow

**Symptom:** Page loads taking >2 seconds, especially `/admin/runs` or trend charts.

Get a SQL session via Laravel Cloud's managed Postgres console (or `cloud db:shell`); locally use `spin exec -T postgres psql -U vantagejobs vantagejobs`.

**Step 1:** Check connection count and active queries.

```sql
SELECT pid, usename, application_name, state, query_start, query
FROM pg_stat_activity
WHERE state != 'idle'
ORDER BY query_start;
```

**Step 2:** Check table sizes.

```sql
SELECT
  schemaname,
  tablename,
  pg_size_pretty(pg_total_relation_size(schemaname || '.' || tablename)) AS total_size
FROM pg_tables
WHERE schemaname = 'public'
ORDER BY pg_total_relation_size(schemaname || '.' || tablename) DESC
LIMIT 20;
```

**Step 3:** Vacuum and analyze if rows have churned heavily.

```bash
php artisan db:vacuum-deep
# Or manually:
# psql -c "VACUUM ANALYZE roles;"
```

**Step 4:** Check for missing indexes.

```sql
SELECT
  relname,
  seq_scan,
  idx_scan,
  seq_scan - idx_scan AS suspicious
FROM pg_stat_user_tables
WHERE seq_scan - idx_scan > 0
ORDER BY suspicious DESC
LIMIT 10;
```

A table with many sequential scans and few index scans likely needs an index.

### Storage filling up

**Symptom:** Cloud's metrics panel shows the Postgres tier nearing its storage limit, or the compute container is alarming on ephemeral disk.

**On Laravel Cloud Postgres:**
- Storage usage and tier are visible in the Cloud dashboard. If you're near the ceiling, either upgrade the tier or run archival (see [Database growth](#database-growth-tracking) below). There is no "free up disk by deleting random files" lever on managed Postgres; growth is structural.

**On the compute container:**
- Scrape artifacts live in `storage/scrape-artifacts/` and should auto-purge after 7 days. Verify the purge job is running:
  ```bash
  cloud shell --env=production
  php artisan schedule:list | grep purge
  php artisan artifacts:purge --dry-run
  ```
- Container storage is ephemeral; logs do not accumulate across releases. If you're seeing alerts during a single release, the purge job is failing.
- **Off-platform backup files (Backblaze B2)** — these should not accumulate inside Cloud; they're streamed out to B2 by `php artisan backup:run`. If `pg_dump` files appear inside the container after a backup run, the upload step is broken; check `BACKUP_B2_*` env vars in Cloud's secret store.

### Database growth tracking

Expected growth per year (PRD section 8.9):
- ~3.6M role-snapshot rows
- ~50–100k Events
- ~15k AggregatorListings at steady state (90-day retention)
- Year 2 total: 8–12 GB

If growth is materially higher, partition the `roles` snapshot table by month:

```bash
php artisan db:partition-roles --by=month
```

Or move closed roles older than 1 year to archive:

```bash
php artisan db:archive-old-roles --days=365
```

---

## Backup restore

### Restore paths

Two backup sources exist. Choose based on the failure mode.

| Failure | Restore from |
|---|---|
| Bad migration, accidental delete, single-table corruption | Laravel Cloud Postgres PITR (fastest, finest granularity) |
| Cloud-side incident, account compromise, region failure | Off-platform Backblaze B2 `pg_dump` (slowest, most independent) |

### Primary restore: Laravel Cloud Postgres PITR

Use Cloud's dashboard or `cloud db:restore --to '2026-05-18T14:32:00Z'`. PITR rolls the production database back to the chosen timestamp. Verify in the Cloud console before promoting the restored snapshot.

### Verify off-platform backups exist and work

**Monthly verification** (also runs automatically per PRD section 8.3, but verify by hand at least once):

```bash
# Locally (Spin stack must be up so Postgres is reachable)

# 1. Download the latest backup from B2
b2 download-file-by-name jobs-backups daily/$(date +%Y-%m-%d).sql.gz /tmp/restore-test.sql.gz

# 2. Decompress
gunzip /tmp/restore-test.sql.gz

# 3. Restore to a scratch DB inside the Spin Postgres container
spin exec -T postgres createdb -U vantagejobs jobs_restore_test
spin exec -T postgres psql -U vantagejobs jobs_restore_test < /tmp/restore-test.sql

# 4. Sanity check
spin exec -T postgres psql -U vantagejobs jobs_restore_test -c "SELECT count(*) FROM tenants;"
spin exec -T postgres psql -U vantagejobs jobs_restore_test -c "SELECT count(*) FROM companies;"

# 5. Clean up
spin exec -T postgres dropdb -U vantagejobs jobs_restore_test
rm /tmp/restore-test.sql
```

### Full disaster restore from B2 (Cloud is gone)

**Scenario:** Laravel Cloud is unreachable for a sustained period, or the account is compromised. You need the data back somewhere else.

```bash
# 1. Stand up a new Postgres instance (a fresh Cloud Postgres on a different account, a Hetzner VPS, or another managed Postgres provider).

# 2. Download latest backup from B2
b2 download-file-by-name jobs-backups daily/{date}.sql.gz /tmp/restore.sql.gz
gunzip /tmp/restore.sql.gz

# 3. Restore
psql "$RESTORE_DATABASE_URL" < /tmp/restore.sql

# 4. Point a new Laravel deployment at the restored DB (Cloud env or otherwise). Run pending migrations (in case the backup is older than the code):
php artisan migrate --force

# 5. Re-seed env-driven settings if needed
php artisan db:seed --class=ConfigSeeder

# 6. Verify
php artisan tinker
>>> App\Models\Tenant::count();
>>> App\Models\Company::count();
>>> App\Models\Role::where('is_open', true)->count();
```

### Partial restore (single table)

If only one table is corrupt and PITR's granularity doesn't fit, extract from the B2 dump and apply selectively:

```bash
pg_restore --list /tmp/restore.sql | grep "TABLE DATA.*companies"
pg_restore --data-only --table=companies /tmp/restore.sql | psql "$DATABASE_URL"
```

---

## Adding a new source

**Prereq:** Read `docs/SOURCES.md` and confirm the source isn't in the excluded list.

**Steps:**

1. **Add the source's spec to `docs/SOURCES.md`** in the appropriate category. Use the existing entry format (endpoint, auth, rate limits, response shape, parsing notes, gotchas).
2. **Create the scraper class** implementing `ScraperInterface` (ATS) or `AggregatorScraperInterface` (aggregator). Place it under `app/Scrapers/Sources/{SourceName}Scraper.php`.
3. **Register the scraper** in the source registry (`config/scrapers.php` or wherever your registry lives).
4. **Add fixture data for tests** under `tests/fixtures/scrapers/{source}/`. Real-world response captures, anonymized if needed.
5. **Write tests:**
   - Unit test for the scraper against fixture data (assert parsed fields).
   - Idempotency test (run twice → zero new Events on second run).
6. **Update polling cadence** in the scheduler (`app/Console/Kernel.php`).
7. **Add an `AggregatorSource` row** in the DB (for aggregator-type sources) or update the per-company `ats_type` enum (for ATS-type sources).
8. **Run a manual scrape** to verify end-to-end:
   ```bash
   php artisan scrape:source {name} --verbose
   ```
9. **Monitor for 1 week** before relying on it. Check `/admin/runs` daily.

---

## Adding a company

**Via UI:** Go to `/companies/new`, paste the website URL. The ATS auto-detector will attempt to detect Greenhouse / Lever / Ashby / Workable / Personio / Recruitee. Fall back to manual entry if it can't.

**Via CSV:** Use `/import` for batches. Idempotent on website domain.

**Via tinker (for power moves):**

```bash
cloud shell --env=production
php artisan tinker

# TenantContext must be set first so the BelongsToTenant observer can stamp tenant_id
>>> $t = App\Tenancy\Tenant::first();
>>> app(App\Tenancy\TenantContext::class)->setCurrent($t);
>>> $c = App\Models\Company::create([
...     'name' => 'Acme Corp',
...     'website' => 'https://acme.com',
...     'careers_url' => 'https://acme.com/careers',
...     'ats_type' => 'greenhouse',
...     'ats_slug' => 'acme',
...     'tier' => 'B',
...     'status' => 'watching',
... ]);
>>> dispatch(new App\Jobs\ScrapeCompany($c));
```

If you forget the `setCurrent()` call, the observer throws to prevent orphaned writes. That's the intended tripwire, not a bug.

---

## Outage response

### Site returns 502 / 503 / 504

**Step 1:** Check Laravel Cloud's status page and the environment's health in the Cloud dashboard. If Cloud is having an incident, there's nothing to do but wait or fall back to the disaster-restore path (see [Full disaster restore from B2](#full-disaster-restore-from-b2-cloud-is-gone)).

**Step 2:** If Cloud is green, tail logs.

```bash
cloud logs --env=production --tail
```

Look for fatal errors, unhandled exceptions, or boot-time failures from the most recent deploy.

**Step 3:** Verify the last deploy succeeded.

```bash
cloud deployments --env=production
```

A failed migration or build error leaves the previous release running but throws errors on push. If the latest entry is `failed`, address that.

**Step 4:** Verify Postgres connectivity from the app.

```bash
cloud shell --env=production
php artisan tinker
>>> DB::connection()->getPdo();
```

**Step 5:** If everything looks green and the site is still broken, roll back to the last known-good release (see [Rollback](#rollback)).

### Health endpoint stays red

- `/up` is a dumb liveness check (no DB query). If it's red, the PHP runtime itself is broken — see Step 2 above.
- `/health` (auth required) returns rich JSON: DB connectivity, last successful scrape per source, queue depth. If `/health` is red but `/up` is green, the app is up but a dependency is failing.

If no recent scrape: the Cloud scheduler may not be running (see [Scheduler debugging](#scheduler-debugging)).

---

## Rollback

### A bad deploy broke production

**Cloud rollback (preferred):**

```bash
cloud rollback --env=production
```

This points production at the previous release. Instant cutover; the database is not rolled back. If the bad deploy ran destructive migrations, see the next subsection.

You can also roll back from the Cloud dashboard: select the environment, pick a prior successful release, click "Promote."

**If `cloud rollback` is not available** (older deploys aged out, edge case):

```bash
# Revert the offending commit locally
git revert <bad-sha>
git push origin main
```

Cloud builds and deploys the revert as a normal release.

### A bad migration broke the schema

**Step 1:** Roll back the application release first:

```bash
cloud rollback --env=production
```

The old code is now running against the new schema. If the old code is compatible with the new schema (additive migration), you're done — fix forward.

**Step 2:** If the migration was destructive (dropped a column you needed), restore the affected data from PITR or B2:

1. Use Cloud Postgres PITR to a moment just before the migration ran (see [Primary restore](#primary-restore-laravel-cloud-postgres-pitr)).
2. Then re-deploy the corrected migrations forward via a normal `git push`.

Never run `migrate:rollback` against production without first taking a fresh PITR checkpoint, in case the down-migration has its own bugs.

---

## Secret rotation

All production secrets live in Laravel Cloud's encrypted environment store. Update via `cloud env:set KEY=value --env=production`, which triggers a re-deploy on the next release. For instant effect, use `cloud env:set KEY=value --env=production --restart`.

### Rotate `APP_KEY`

**Warning:** This invalidates all encrypted data (sessions, encrypted columns). Users will be logged out.

```bash
NEW_KEY=$(php -r 'echo "base64:" . base64_encode(random_bytes(32));')
cloud env:set APP_KEY="$NEW_KEY" --env=production --restart
```

If you have encrypted DB columns, re-encrypt them before flipping the key. There are no encrypted columns in v1 (the User TOTP secret is hashed via Fortify's mechanism, not encrypted with `APP_KEY` directly).

### Rotate DB password

Cloud-managed Postgres credentials are managed by Cloud. Use `cloud db:rotate-password --env=production`. Cloud writes the new password into the linked env vars and restarts the app.

### Rotate Postmark/Resend API key

Generate new key in provider dashboard. Then:

```bash
cloud env:set POSTMARK_TOKEN=new_token --env=production --restart
# or
cloud env:set RESEND_API_KEY=new_key --env=production --restart
```

Verify with a test email from `cloud shell`. Revoke old key after 24 hours.

### Rotate Backblaze B2 keys

Generate new application key in B2 dashboard with `writeFiles` + `readFiles` permissions on the `vantagejobs-backups` bucket.

```bash
cloud env:set BACKUP_B2_KEY_ID=new_id --env=production
cloud env:set BACKUP_B2_APPLICATION_KEY=new_key --env=production --restart
cloud shell --env=production
php artisan backup:run
```

Revoke old key after the first successful backup with the new key.

### Rotate GitHub personal access token

Generate new fine-grained PAT in GitHub settings, scoped to `public_repo` read.

```bash
cloud env:set GITHUB_TOKEN=new_token --env=production --restart
cloud shell --env=production
php artisan github:test
```

### Rotate the iCal feed secret (calendar export)

If someone has your `.ics` URL and you want to invalidate it:

```bash
cloud shell --env=production
php artisan calendar:rotate-secret
```

Old URL stops working immediately. New URL appears in `/settings/account`. Re-subscribe in calendar clients.

---

## GitHub API issues

### Rate-limited

**Symptom:** `403 Forbidden` from GitHub API. `X-RateLimit-Remaining: 0` in response headers.

**Step 1:** Verify auth is being sent.

```bash
curl -i -H "Authorization: token $GITHUB_TOKEN" https://api.github.com/rate_limit
```

You should see `5000` as the limit, not `60`. If `60`, auth isn't being sent.

**Step 2:** If genuinely hitting 5000/hr, the GitHub poller is being too aggressive. Reduce concurrency or batch better. See PRD section 6.3.3.

**Step 3:** Check token validity.

```bash
curl -i -H "Authorization: token $GITHUB_TOKEN" https://api.github.com/user
```

`401` means the token is revoked or expired (fine-grained PATs expire). Generate a new one.

---

## Triage queue overflow

### Too many listings to process

**Symptom:** The queue has > 200 items and growing.

**Step 1:** Run the volume cap manually if it didn't trigger.

```bash
php artisan triage:apply-volume-cap
```

This moves overflow listings to status `overflow` per PRD section 6.4.5.

**Step 2:** Bulk-dismiss obvious junk.

In `/discover`, use the keyword filter (e.g., `crypto`, `blockchain`, `web3`) and bulk-dismiss. Add the keyword to the auto-dismiss list if it's recurring.

**Step 3:** Check auto-dismiss isn't broken.

```bash
php artisan tinker
>>> App\Models\DismissedCompany::where('auto_dismiss', true)->pluck('normalized_name');
```

If companies you've dismissed 3+ times aren't in this list, the auto-dismiss logic has a bug.

---

## Cloud runtime troubleshooting

### Production runtime won't boot

```bash
cloud logs --env=production --tail
cloud deployments --env=production
```

**Common causes:**
- A failed migration in the last build. `cloud deployments` shows `failed`. Fix the migration locally, push.
- Bad env var (typo in a key Laravel reads at boot). `cloud env --env=production` to inspect, `cloud env:set` to correct.
- PHP fatal during config caching. Check `cloud logs` for `php artisan config:cache` output during the deploy.
- TLS issue on the custom domain. Check the domain's DNS, especially CNAME or A record pointing at Cloud's load balancer per `cloud domains`.

### Worker mode regressions (if you ever switch on)

Per PRD section 9, v1 runs FrankenPHP in **classic mode**. If worker mode somehow got enabled in `cloud.yaml` (or whatever Cloud's manifest filename is), revert it: the manifest should not specify Octane/worker mode in v1.

### TLS / domain issues

Laravel Cloud manages TLS at the edge. If the custom domain isn't serving HTTPS:

1. `cloud domains --env=production` — verify the domain is attached and verified.
2. Check the registrar's DNS records match what Cloud requires.
3. If the domain shows `pending verification`, Cloud cannot complete the ACME challenge. Most common cause: wrong DNS record or CAA record blocking Let's Encrypt.

---

## Scheduler debugging

### Scheduled tasks not running

**Symptom:** `/admin/runs` shows no recent scrapes. `last_checked_at` on Companies is stale.

**Step 1:** Verify the Cloud scheduled task is configured and enabled.

```bash
cloud schedule --env=production
```

Should show `php artisan schedule:run` firing every minute. If missing, re-add it via the Cloud dashboard or `cloud schedule:add "* * * * *" "php artisan schedule:run"`.

**Step 2:** Check what Laravel's scheduler thinks it should run.

```bash
cloud shell --env=production
php artisan schedule:list
```

**Step 3:** Run the schedule manually to see errors.

```bash
cloud shell --env=production
php artisan schedule:run
```

Errors will print immediately.

**Step 4:** If a specific command is registered but never runs, check its `->between()` / `->when()` constraints in `app/Console/Kernel.php` and the production timezone. Cloud runs in UTC by default; Laravel's scheduler honors the app's `app.timezone` config when interpreting `->dailyAt()` / `->cron()` expressions.

---

## Local dev troubleshooting

### `spin up` fails or services don't come up cleanly

```bash
spin down
docker system prune --volumes # if you suspect stale state
spin up --build
```

If FrankenPHP starts but the app errors on first request, check `.env` against `.env.example` for any new vars the latest commit expects.

### Database state out of sync after a branch switch

```bash
spin exec -T php php artisan migrate:fresh --seed
```

This nukes the local Postgres DB and reseeds. Safe locally; never run against Cloud.

### Pint/test commands hang or produce garbled output

You're probably missing the `-T` flag on `spin exec` / `spin run` in a subprocess context (CI, agent, script). Add `-T`:

```bash
spin exec -T php ./vendor/bin/pint --dirty --format agent
spin exec -T php php artisan test --compact --parallel --recreate-databases
```

---

## Tenancy invariants

### Suspect a tenant_id leak (data from the wrong tenant visible somewhere)

In v1 there's only one Tenant, so a leak is invisible by definition. The risk surfaces once a second Tenant exists. Treat these as code-review checklist items, not runtime debugging:

- Every domain table migration includes a `tenant_id` foreign key. Search: `grep -L 'tenant_id' database/migrations/*.php` should return only `tenants` and Laravel framework migrations.
- Every Eloquent model under `app/Models/` uses the `BelongsToTenant` trait. Search: `grep -L 'BelongsToTenant' app/Models/*.php` should be empty (or only contain models that intentionally span tenants, which currently is `Tenant` itself).
- Every artisan command that touches tenant data calls `TenantContext::setCurrent()` at the top, or iterates over `Tenant::query()->each(...)` setting context per iteration.
- The model observer throws `RequiresTenantContextException` if an insert happens without a current tenant. Tests assert this fires for any new domain model.

If a leak is suspected at runtime in v1, it usually means one of these invariants was bypassed (a manual SQL query, a `withoutGlobalScope` call without good reason, or a forgotten `setCurrent()`). Search the codebase for those patterns first.

---

## Common gotchas

A growing list of "I forgot this once and lost an hour."

- **Timezone bugs:** Always store in UTC. Display in user TZ. The dashboard shows "3 days ago" relative but use absolute on hover to spot off-by-ones.
- **Greenhouse "metadata" is per-customer.** Don't write code that assumes a specific metadata key exists. Iterate and pattern-match.
- **HN format drifts.** When the parser starts losing confidence on HN, look at the actual recent posts before changing code.
- **Remotive's first JSON element is metadata.** Skip the legal notice element.
- **layoffs.fyi's Airtable URL rotates.** Don't hard-code the embed URL; resolve it from the homepage HTML on each run.
- **GitHub auth token absent → 60/hour limit instead of 5000.** Always check rate-limit headers.
- **Cloud's edge handles TLS** — don't try to renew certs yourself or override the FrankenPHP TLS block. The platform owns it.
- **`pg_dump` without `--no-owner --no-acl`** makes restores painful across environments. The backup script should always use those flags.
- **A `last_checked_at` of NULL means "never checked," not "checked recently."** Watch for `NULL` in sorts and filters.
- **Inertia shared props are reset on every request.** Don't put long-lived state there.
- **Tailwind v4 doesn't use a config file the same way as v3.** Customizations live in CSS, not JS config.
- **Forgetting `TenantContext::setCurrent()` in an artisan command** throws on the first insert (intended). Cron-mode "ran once but did nothing" usually means the schedule called the command outside a tenant context — wrap the command's body in `$tenants->each(fn ($t) => $context->withTenant($t, fn () => ...))` or similar.
- **`cloud shell` is read-only by default.** To run a write-capable shell, pass `--write` (or whatever Cloud's current flag is). Avoid running migrations from interactive shell; let the build pipeline do it on deploy.
- **Spin `-T` flag is mandatory in agent/CI contexts.** Without it, Compose can hang waiting on a non-existent TTY. See [Local dev troubleshooting](#local-dev-troubleshooting).

---

**Last updated:** May 2026. Add to this doc whenever you debug something that took >10 minutes.
