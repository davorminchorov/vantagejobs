<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Bookmark;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

final class BookmarksExpireCommand extends Command
{
    protected $signature = 'bookmarks:expire';

    protected $description = 'Hard-delete bookmarks whose expires_at has elapsed.';

    public function handle(): int
    {
        $count = Bookmark::query()
            ->where('expires_at', '<', CarbonImmutable::now())
            ->delete();

        $this->line((string) json_encode([
            'command' => 'bookmarks:expire',
            'deleted' => $count,
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
