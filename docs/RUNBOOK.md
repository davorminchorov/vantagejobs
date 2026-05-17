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
| FrankenPHP won't start | [FrankenPHP troubleshooting](#frankenphp-troubleshooting) |
| Cron isn't running | [Cron debugging](#cron-debugging) |

---

## Auth recovery

### Locked out of the only account

**Symptom:** Login attempts fail. Password reset email doesn't arrive, or I forgot the email.

**Recovery from server (SSH access required):**

```bash
ssh deploy@your-server
cd /var/www/jobs
php artisan user:reset-password
```

This prompts for the new password and writes it directly. Logs the action to `AuditLog`.

**If 2FA is the problem (lost authenticator):**

```bash
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
php artisan tinker
>>> App\Models\ScrapeRun::latest()->where('source', 'greenhouse')->take(5)->get(['status', 'error_message', 'started_at']);
```

**Step 2:** Inspect a raw artifact (within 7-day retention window).

```bash
ls -la storage/scrape-artifacts/$(date +%Y-%m-%d)/greenhouse/
# Or query: App\Models\ScrapeRunArtifact::where('source', 'greenhouse')->latest()->first()
```

If the artifact has unexpected JSON shape or HTML where JSON should be, the upstream API changed. Update the scraper or parser accordingly.

**Step 3:** Run the scraper manually with verbose output.

```bash
php artisan scrape:source greenhouse --company={slug} --verbose
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
- Server-wide network issue. Check `ping 8.8.8.8` from the box.
- DNS issue. Check `dig api.lever.co`.
- Server time skew (TLS handshakes fail). Check `timedatectl status`.
- Disk full preventing artifact writes. `df -h`.
- Database connection refused. `systemctl status postgresql`.

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

### Disk filling up

**Symptom:** Disk free < 20% on the VPS.

**Step 1:** Find the culprit.

```bash
du -sh /var/* 2>/dev/null | sort -hr | head -10
du -sh /var/www/jobs/storage/* 2>/dev/null | sort -hr | head -10
```

**Step 2:** Likely candidates:

- **Postgres data** at `/var/lib/postgresql/{version}/main` — see [Database growth](#database-growth) below.
- **Scrape artifacts** at `storage/scrape-artifacts/` — should auto-purge after 7 days. Verify the purge job is running:
  ```bash
  php artisan schedule:list | grep purge
  php artisan artifacts:purge --dry-run
  ```
- **Laravel logs** at `storage/logs/` — rotate them:
  ```bash
  truncate -s 0 storage/logs/laravel.log
  # Or configure log rotation in config/logging.php
  ```
- **Backup files** — backups should be on B2, not the VPS. If `pg_dump` files are accumulating locally, the upload step is broken.

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

### Verify your backups exist and work

**Monthly verification** (also runs automatically per PRD section 8.3, but verify by hand at least once):

```bash
# 1. Download the latest backup from B2
b2 download-file-by-name jobs-backups daily/$(date +%Y-%m-%d).sql.gz /tmp/restore-test.sql.gz

# 2. Decompress
gunzip /tmp/restore-test.sql.gz

# 3. List contents (structural check)
pg_restore --list /tmp/restore-test.sql | head -50

# 4. Restore to a scratch DB
createdb jobs_restore_test
psql jobs_restore_test < /tmp/restore-test.sql

# 5. Sanity check
psql jobs_restore_test -c "SELECT count(*) FROM companies;"

# 6. Clean up
dropdb jobs_restore_test
rm /tmp/restore-test.sql
```

### Full disaster restore

**Scenario:** The VPS is gone. You have a fresh box. You need the data back.

```bash
# 1. Provision a new VPS, install Postgres 18, create the DB
sudo -u postgres createdb jobs

# 2. Download latest backup
b2 download-file-by-name jobs-backups daily/{date}.sql.gz /tmp/restore.sql.gz
gunzip /tmp/restore.sql.gz

# 3. Restore
psql jobs < /tmp/restore.sql

# 4. Run pending migrations (in case the backup is older than the code)
cd /var/www/jobs
php artisan migrate

# 5. Re-seed env-driven settings
php artisan db:seed --class=ConfigSeeder

# 6. Verify
php artisan tinker
>>> App\Models\Company::count();
>>> App\Models\Role::where('is_open', true)->count();
```

### Partial restore (single table)

If only one table is corrupt:

```bash
# Extract just that table from a SQL dump
pg_restore --list /tmp/restore.sql | grep "TABLE DATA.*companies"
pg_restore --data-only --table=companies /tmp/restore.sql | psql jobs
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
php artisan tinker
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

---

## Outage response

### Site returns 502 / 503 / 504

**Step 1:** Check if FrankenPHP is running.

```bash
systemctl status frankenphp
```

If dead, start it:

```bash
systemctl start frankenphp
```

If it won't start, check the logs:

```bash
journalctl -u frankenphp -n 100
```

**Step 2:** Check database connectivity.

```bash
systemctl status postgresql
sudo -u postgres psql -c "SELECT 1;"
```

**Step 3:** Check disk space (full disk = mysterious failures).

```bash
df -h
```

**Step 4:** Check Laravel logs for fatal errors.

```bash
tail -n 200 /var/www/jobs/storage/logs/laravel.log
```

### Health endpoint stays red

`/up` returns 500 means the platform can't reach the DB or no scraper has run in the last 48h.

- DB unreachable: see Step 2 above.
- No recent scrape: cron may not be running (see [Cron debugging](#cron-debugging)).

---

## Rollback

### A bad deploy broke production

**With Forge:**
- Forge dashboard → Site → Deployments → Click previous successful deployment → "Redeploy"

**Manual (if not using Forge):**

```bash
ssh deploy@your-server
cd /var/www/jobs
git log --oneline -10
# Identify the last known-good commit, e.g. abc1234
git checkout abc1234
composer install --no-dev --optimize-autoloader
php artisan migrate:rollback --step=N  # if migrations changed
npm ci && npm run build
systemctl restart frankenphp
```

### A bad migration broke the schema

```bash
php artisan migrate:rollback --step=1
```

If the migration was destructive (dropped a column you needed):

```bash
# 1. Restore from the most recent backup
# (See "Backup restore" section)
# 2. Re-run only the migrations newer than the backup
php artisan migrate
```

---

## Secret rotation

### Rotate `APP_KEY`

**Warning:** This invalidates all encrypted data (sessions, encrypted columns). Users will be logged out.

```bash
php artisan key:generate --show
# Copy the new key, update .env
# Re-encrypt any encrypted DB columns if you have any
# Restart frankenphp
systemctl restart frankenphp
```

### Rotate DB password

```bash
sudo -u postgres psql -c "ALTER USER jobs_app WITH PASSWORD 'new_strong_password';"
# Update DB_PASSWORD in .env
systemctl restart frankenphp
```

### Rotate Postmark/Resend API key

Generate new key in provider dashboard. Update `.env`. Restart FrankenPHP. Verify with a test email. Revoke old key after 24 hours.

### Rotate Backblaze B2 keys

Generate new application key in B2 dashboard with `writeFiles` + `readFiles` permissions on the `jobs-backups` bucket. Update `.env`. Run a manual backup to verify:

```bash
php artisan backup:run
```

Revoke old key after the first successful backup with the new key.

### Rotate GitHub personal access token

Generate new fine-grained PAT in GitHub settings, scoped to `public_repo` read. Update `GITHUB_TOKEN` in `.env`. Verify:

```bash
php artisan github:test
```

### Rotate the iCal feed secret (calendar export)

If someone has your `.ics` URL and you want to invalidate it:

```bash
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

## FrankenPHP troubleshooting

### Won't start

```bash
journalctl -u frankenphp -n 100
```

**Common causes:**
- Port 80/443 already in use (`lsof -i :80`)
- Caddyfile syntax error (it's embedded — check FrankenPHP config in your systemd unit)
- TLS cert renewal failed (check the Caddy logs)
- PHP fatal error during boot

### Worker mode regressions (if you ever switch on)

Per PRD section 9, you're on **classic mode** in v1. If for some reason worker mode got toggled:

```bash
grep -r "FRANKENPHP_CONFIG" /etc/systemd/system/frankenphp.service
```

Should NOT contain `worker` directive. If it does, remove it and restart.

### TLS cert not renewing

FrankenPHP's embedded Caddy auto-renews Let's Encrypt certs. If a cert is about to expire:

```bash
systemctl restart frankenphp
```

This triggers a renewal attempt. If it still fails, check the domain's DNS and port 80/443 accessibility from the public internet (Let's Encrypt's ACME challenge requires both).

---

## Cron debugging

### Scheduled tasks not running

**Symptom:** `/admin/runs` shows no recent scrapes. `last_checked_at` on Companies is stale.

**Step 1:** Verify cron is installed for the deploy user.

```bash
crontab -u deploy -l
```

Should contain something like:

```
* * * * * cd /var/www/jobs && php artisan schedule:run >> /dev/null 2>&1
```

If empty, re-install:

```bash
echo '* * * * * cd /var/www/jobs && php artisan schedule:run >> /var/log/jobs-schedule.log 2>&1' | crontab -u deploy -
```

**Step 2:** Verify cron daemon is running.

```bash
systemctl status cron  # Ubuntu
```

**Step 3:** Check what the schedule thinks it should run.

```bash
cd /var/www/jobs
php artisan schedule:list
```

**Step 4:** Run the schedule manually to see errors.

```bash
php artisan schedule:run
```

Errors will print immediately.

---

## Common gotchas

A growing list of "I forgot this once and lost an hour."

- **Timezone bugs:** Always store in UTC. Display in user TZ. The dashboard shows "3 days ago" relative but use absolute on hover to spot off-by-ones.
- **Greenhouse "metadata" is per-customer.** Don't write code that assumes a specific metadata key exists. Iterate and pattern-match.
- **HN format drifts.** When the parser starts losing confidence on HN, look at the actual recent posts before changing code.
- **Remotive's first JSON element is metadata.** Skip the legal notice element.
- **layoffs.fyi's Airtable URL rotates.** Don't hard-code the embed URL; resolve it from the homepage HTML on each run.
- **GitHub auth token absent → 60/hour limit instead of 5000.** Always check rate-limit headers.
- **FrankenPHP needs ports 80 AND 443 open** for Let's Encrypt to work, even if you only serve on 443.
- **`pg_dump` without `--no-owner --no-acl`** makes restores painful across servers. The backup script should always use those flags.
- **A `last_checked_at` of NULL means "never checked," not "checked recently."** Watch for `NULL` in sorts and filters.
- **Inertia shared props are reset on every request.** Don't put long-lived state there.
- **Tailwind v4 doesn't use a config file the same way as v3.** Customizations live in CSS, not JS config.

---

**Last updated:** May 2026. Add to this doc whenever you debug something that took >10 minutes.
