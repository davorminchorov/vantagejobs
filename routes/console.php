<?php

declare(strict_types=1);

use App\Tenancy\Tenant;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled aggregator runs
|--------------------------------------------------------------------------
|
| Per SOURCES.md cadences:
|   - HackerNews "Who is Hiring": monthly on the 3rd and again on the 5th
|     (catches late-arriving postings to the monthly thread).
|   - Remotive / Himalayas / We Work Remotely / RemoteOK: once daily.
|
| All runs invoke the per-source aggregator:run command per active tenant.
| For now there's exactly one tenant (single-user platform); when that
| changes, swap this for a tenant-aware scheduler.
|
| Bookmarks lifecycle:
|   - bookmarks:warn-expiring runs daily at 06:00 UTC (T-3 warning).
|   - bookmarks:expire runs daily at 06:05 UTC (hard delete after expiry).
*/

$tenantSlug = static fn (): ?string => Tenant::query()->orderBy('id')->value('slug');

/*
|--------------------------------------------------------------------------
| ATS company scraping
|--------------------------------------------------------------------------
|
| Polls every tracked company's Greenhouse/Lever/Ashby/Workable/
| SmartRecruiters/Personio/Recruitee/Teamtailor board. --queue dispatches
| one RunCompanyScrapeJob per company (ShouldBeUnique-guarded) instead of
| running the orchestrator inline in the scheduler process, so one slow or
| crashing company can't stall — or take down — the rest of the sweep.
|
| Tier-aware cadence (A/B/C-tier PRD §6.2, A tightened for early-applicant
| freshness): A every 2h, B every 12h, C every 24h, computed from each
| company's latest scrape_runs.started_at. The tick runs hourly so every
| tier's window is checked promptly; ScrapeAllCommand itself skips companies
| that aren't due yet, so most ticks queue nothing for most companies.
*/
Schedule::call(static function () use ($tenantSlug): void {
    $slug = $tenantSlug();
    if (! is_string($slug) || $slug === '') {
        return;
    }
    Artisan::call('scrape:all', ['--tenant' => $slug, '--queue' => true]);
})->name('scrape:all')->hourly()->withoutOverlapping();

Schedule::call(static function () use ($tenantSlug): void {
    $slug = $tenantSlug();
    if (! is_string($slug) || $slug === '') {
        return;
    }
    Artisan::call('aggregator:run', ['source' => 'hackernews', '--tenant' => $slug]);
})->name('aggregator:hackernews:3rd')->monthlyOn(3, '07:00');

Schedule::call(static function () use ($tenantSlug): void {
    $slug = $tenantSlug();
    if (! is_string($slug) || $slug === '') {
        return;
    }
    Artisan::call('aggregator:run', ['source' => 'hackernews', '--tenant' => $slug]);
})->name('aggregator:hackernews:5th')->monthlyOn(5, '07:00');

// Phase 2 + deferred-optional aggregators (SOURCES.md §2, §3) and the
// later tier 1 (Jobicy / Arbeitnow) + tier 2 (Python.org / Django /
// Elixir Jobs / HexHire) additions. All poll once daily; per-source ToS
// / rate-limit caps live in config/scrapers.php → 'aggregators'. Each
// entry must also have an enabled AggregatorSource row for the target
// tenant — without it aggregator:run is a no-op for that type.
//
// Tenants are resolved per-run: every tenant that has at least one
// enabled aggregator source gets a run. That way the schedule keeps
// working as new tenants spin up without code changes (and doesn't
// silently target the wrong tenant when tenant_id ordering shifts).
$aggregatorTenantSlugs = static function (): array {
    return Tenant::query()
        ->whereExists(static function ($q): void {
            $q->select('id')
                ->from('aggregator_sources')
                ->whereColumn('aggregator_sources.tenant_id', 'tenants.id')
                ->where('aggregator_sources.enabled', true);
        })
        ->orderBy('id')
        ->pluck('slug')
        ->all();
};

// Every other aggregator type (remotive, himalayas, weworkremotely, remoteok,
// fourdayweek, workingnomads, adzuna, larajobs, jobicy, arbeitnow, python_org,
// django_news, elixir_jobs, hexhire) is driven by AggregatorSource.next_run_at
// rather than a hardcoded per-source cron entry: this sweep dispatches
// RunAggregatorScrapeJob (ShouldBeUnique-guarded) for every enabled source
// whose next_run_at has elapsed. AggregatorOrchestrator already advances
// next_run_at by cadence_minutes on every run; seeded cadence_minutes comes
// from AggregatorSourceType::defaultCadenceMinutes() (6h for permissive
// feeds, daily for rate-limited/cached/ToS-bound ones). HackerNews keeps its
// explicit monthly entries above — see AggregatorDispatchDueCommand.
Schedule::command('aggregator:dispatch-due')->everyFifteenMinutes()->withoutOverlapping();

Schedule::command('bookmarks:warn-expiring')->dailyAt('06:00');
Schedule::command('bookmarks:expire')->dailyAt('06:05');

// Calendar reminder sweep (Phase 5): queues reminder emails for events whose
// reminder lead-time has elapsed, across all tenants. Runs every minute so
// reminder lead-times resolve to roughly the requested moment.
Schedule::command('calendar:send-reminders')->everyMinute()->withoutOverlapping();

// Roll the parser confidence stats forward 30 minutes before the artifact
// purge — they share no rows but it keeps the nightly band readable.
Schedule::command('parser:rollup-confidence')->dailyAt('02:30');
Schedule::command('scrape-artifacts:purge')->dailyAt('03:00');

// scrape_runs retention: deletes rows past config('scrapers.run_retention_days').
// Runs after the artifact purge so a pruned run's (already nulled) artifacts
// have had their own chance to go first.
Schedule::command('scrape-runs:prune')->dailyAt('03:15');

// Snapshot the day's company state at 04:00 UTC. Other signal jobs
// (github, blog, layoffs, quality) update the same rows in place later.
Schedule::call(static function () use ($tenantSlug): void {
    $slug = $tenantSlug();
    if (! is_string($slug) || $slug === '') {
        return;
    }
    Artisan::call('signals:snapshot:daily', ['--tenant' => $slug]);
})->name('signals:snapshot:daily')->dailyAt('04:00');

// Layoffs cross-reference (Phase 4 §6.3.2). Reads a locally-staged
// Kaggle export — Kaggle auto-download is a follow-up.
Schedule::call(static function () use ($tenantSlug): void {
    $slug = $tenantSlug();
    if (! is_string($slug) || $slug === '') {
        return;
    }
    Artisan::call('signals:layoffs:sync', [
        '--tenant' => $slug,
        '--source' => 'local',
        '--path' => storage_path('app/signals/layoffs.csv'),
    ]);
})->name('signals:layoffs:sync')->dailyAt('04:15');

// TrueUp layoffs fallback (SOURCES.md §4.2). Runs weekly — same cadence
// as layoffs.fyi but offset by a day so a TrueUp blow-up doesn't take
// the layoffs.fyi pass with it.
Schedule::call(static function () use ($tenantSlug): void {
    $slug = $tenantSlug();
    if (! is_string($slug) || $slug === '') {
        return;
    }
    Artisan::call('signals:layoffs:trueup', ['--tenant' => $slug]);
})->name('signals:layoffs:trueup')->weeklyOn(2, '04:20');

// GitHub org activity (Phase 4 §6.3.3). Weekly to respect the 5000
// req/h auth budget once the watchlist grows.
Schedule::call(static function () use ($tenantSlug): void {
    $slug = $tenantSlug();
    if (! is_string($slug) || $slug === '') {
        return;
    }
    Artisan::call('signals:github:poll', ['--tenant' => $slug]);
})->name('signals:github:poll')->weeklyOn(1, '04:30');

// yc-oss YC mirror sync. Cheap (static JSON), weekly.
Schedule::call(static function () use ($tenantSlug): void {
    $slug = $tenantSlug();
    if (! is_string($slug) || $slug === '') {
        return;
    }
    Artisan::call('signals:funding:yc-oss', ['--tenant' => $slug]);
})->name('signals:funding:yc-oss')->weeklyOn(1, '04:45');

// Engineering-blog RSS poll (Phase 4 §6.3.4). After the snapshot job
// so blog cadence can fold into the quality score later in the day.
Schedule::call(static function () use ($tenantSlug): void {
    $slug = $tenantSlug();
    if (! is_string($slug) || $slug === '') {
        return;
    }
    Artisan::call('signals:blog:poll', ['--tenant' => $slug]);
})->name('signals:blog:poll')->dailyAt('05:00');

// Google News funding scan (Phase 4 §6.3.5). Review-queue pattern — a
// human gates every hit, so weekly is enough.
Schedule::call(static function () use ($tenantSlug): void {
    $slug = $tenantSlug();
    if (! is_string($slug) || $slug === '') {
        return;
    }
    Artisan::call('signals:funding:news', ['--tenant' => $slug]);
})->name('signals:funding:news')->weeklyOn(1, '05:15');

// Ghost-job scan (Phase 4 §6.3.6) and quality-score recompute
// (Phase 4 §6.3.9). Ordered last in the daily band so all signal
// inputs are fresh.
Schedule::call(static function () use ($tenantSlug): void {
    $slug = $tenantSlug();
    if (! is_string($slug) || $slug === '') {
        return;
    }
    Artisan::call('signals:ghost:scan', ['--tenant' => $slug]);
})->name('signals:ghost:scan')->dailyAt('05:30');

Schedule::call(static function () use ($tenantSlug): void {
    $slug = $tenantSlug();
    if (! is_string($slug) || $slug === '') {
        return;
    }
    Artisan::call('signals:quality:recompute', ['--tenant' => $slug]);
})->name('signals:quality:recompute')->dailyAt('05:45');

// ATS-switch detection (Phase 4 §11.2): the orchestrator already
// flags companies as needs_redetection after 7 consecutive 404s.
// This sweep picks them up hourly so a broken slug self-heals
// without manual intervention.
Schedule::call(static function () use ($tenantSlug): void {
    $slug = $tenantSlug();
    if (! is_string($slug) || $slug === '') {
        return;
    }
    Artisan::call('signals:ats:redetect', ['--tenant' => $slug]);
})->name('signals:ats:redetect')->hourly();

/*
|--------------------------------------------------------------------------
| Phase 6: notifications, monitoring, exports & backups
|--------------------------------------------------------------------------
|
| The notification sweeps below run tenant-agnostic (no --tenant) and the
| commands self-gate per recipient: the daily digest fires hourly and only
| sends to recipients whose local configured send hour matches; A-tier
| alerts run every 15 minutes; the weekly summary follows the per-tenant
| scheduler pattern and is day-gated inside the command.
*/

// Daily digest: hourly tick, gated inside the command on each recipient's
// local configured send hour. withoutOverlapping() so a slow run never
// double-fires.
Schedule::command('digest:send-daily')->hourly()->withoutOverlapping();

// A-tier instant alerts: high-signal new roles, swept every 15 minutes.
Schedule::command('notifications:send-a-tier-alerts')->everyFifteenMinutes()->withoutOverlapping();

// Weekly summary: per-tenant, day-gated inside the command. Run daily at the
// configured morning send time so the configured day lands; the per-week gate
// makes a daily-but-day-gated tick safe.
Schedule::call(static function () use ($aggregatorTenantSlugs): void {
    foreach ($aggregatorTenantSlugs() as $slug) {
        Artisan::call('notifications:send-weekly-summary', ['--tenant' => $slug]);
    }
})->name('notifications:send-weekly-summary')
    ->dailyAt((string) config('notifications.digest_send_time', '07:00'))
    ->withoutOverlapping();

// Source-health monitor: hourly sweep across all tenants.
Schedule::command('monitor:source-health')->hourly()->withoutOverlapping();

// Stale scrape_runs reaper: a worker that dies mid-run (OOM, hard restart)
// leaves its row at status='running' forever with nothing to notice.
// Tenant-agnostic — the command sweeps the global scrape_runs table.
Schedule::command('scrape-runs:reap')->hourly()->withoutOverlapping();

// Snooze wake-up: flips SNOOZED aggregator listings back to NEW once
// snoozed_until elapses. Tenant-agnostic global sweep.
Schedule::command('listings:wake-snoozed')->hourly()->withoutOverlapping();

// Listing staleness: moves NEW aggregator listings past each tenant's
// configured expiry window (posted_at, or created_at when undated) to
// EXPIRED so the triage queue only holds postings worth a decision.
Schedule::command('listings:expire-stale')->dailyAt('06:15')->withoutOverlapping();

// Dismissed-listing retention (PRD §6.4.9): hard-deletes DISMISSED aggregator
// listings past each tenant's configured retention window.
Schedule::command('listings:prune-dismissed')->dailyAt('06:20')->withoutOverlapping();

// Aggregator-born role staleness: closes open Roles auto-linked from a
// listing (source like 'aggregator:%') that haven't been re-seen within
// config('scrapers.aggregator_role_stale_days'). Nothing else re-scrapes
// these, so without this sweep a dropped listing's Role would stay open
// forever.
Schedule::command('roles:close-stale-aggregator')->dailyAt('06:25')->withoutOverlapping();

// Export-job retention: prune expired export archives. Tenant-agnostic — the
// command sweeps the global export_jobs table.
Schedule::command('exports:prune')->dailyAt('06:10');

// Nightly database backups (whole-DB pg_dump, no --tenant).
Schedule::command('backup:run')->dailyAt('01:30')->withoutOverlapping();
// Reap older copies after the freshest dump is in place.
Schedule::command('backup:prune')->dailyAt('02:00')->withoutOverlapping();
// Monthly restore verification (honors config('backup.verify_monthly')).
Schedule::command('backup:verify')->monthlyOn(1, '02:15');
