<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Bookmark;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

final class BookmarksWarnExpiringCommand extends Command
{
    protected $signature = 'bookmarks:warn-expiring
        {--days=3 : Warn this many days before expires_at}';

    protected $description = 'Mark expires_warned_at on bookmarks that will expire soon. Drives the in-app nag.';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $threshold = CarbonImmutable::now()->addDays($days);

        $count = Bookmark::query()
            ->whereNull('expires_warned_at')
            ->where('expires_at', '<=', $threshold)
            ->update(['expires_warned_at' => CarbonImmutable::now()]);

        $this->line((string) json_encode([
            'command' => 'bookmarks:warn-expiring',
            'days_ahead' => $days,
            'warned' => $count,
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
