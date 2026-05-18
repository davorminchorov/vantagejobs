<?php

declare(strict_types=1);

namespace App\Signals\Funding;

use RuntimeException;

final class FundingFetchException extends RuntimeException
{
    public static function transport(string $url, string $reason): self
    {
        return new self("Failed to fetch funding feed [{$url}]: {$reason}");
    }

    public static function unparseable(string $url, string $reason): self
    {
        return new self("Failed to parse funding feed [{$url}]: {$reason}");
    }
}
