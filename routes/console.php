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

foreach (['remotive', 'himalayas', 'weworkremotely', 'remoteok'] as $type) {
    Schedule::call(static function () use ($tenantSlug, $type): void {
        $slug = $tenantSlug();
        if (! is_string($slug) || $slug === '') {
            return;
        }
        Artisan::call('aggregator:run', ['source' => $type, '--tenant' => $slug]);
    })->name("aggregator:{$type}:daily")->dailyAt('06:30');
}

Schedule::command('bookmarks:warn-expiring')->dailyAt('06:00');
Schedule::command('bookmarks:expire')->dailyAt('06:05');

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
