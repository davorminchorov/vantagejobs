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

foreach ([
    'remotive', 'himalayas', 'weworkremotely', 'remoteok',
    'fourdayweek', 'workingnomads', 'adzuna', 'larajobs',
    'jobicy', 'arbeitnow',
    'python_org', 'django_news', 'elixir_jobs', 'hexhire',
] as $type) {
    Schedule::call(static function () use ($aggregatorTenantSlugs, $type): void {
        foreach ($aggregatorTenantSlugs() as $slug) {
            Artisan::call('aggregator:run', ['source' => $type, '--tenant' => $slug]);
        }
    })->name("aggregator:{$type}:daily")->dailyAt('06:30')->withoutOverlapping();
}

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
