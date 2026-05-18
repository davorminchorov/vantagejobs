<?php

declare(strict_types=1);

namespace App\Signals\Blog;

use RuntimeException;

final class FeedFetchException extends RuntimeException
{
    public static function transport(string $url, string $reason): self
    {
        return new self("Failed to fetch feed [{$url}]: {$reason}");
    }

    public static function unparseable(string $url, string $reason): self
    {
        return new self("Failed to parse feed [{$url}]: {$reason}");
    }
}
