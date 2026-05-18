<?php

declare(strict_types=1);

namespace App\Signals\Github;

use RuntimeException;

/**
 * Thrown when a GitHub org cannot be fetched (404, 403, timeout, etc.).
 *
 * Callers should treat this as a per-org failure and isolate it from the
 * rest of the poller run.
 */
final class GithubFetchException extends RuntimeException
{
}
