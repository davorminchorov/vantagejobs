<?php

declare(strict_types=1);

namespace App\Backups;

use RuntimeException;

/**
 * Thrown when a pg_dump invocation fails. Surfaced by BackupRunCommand, which
 * catches it, alerts via mail, and exits non-zero so the scheduler logs a
 * failure.
 */
final class BackupFailedException extends RuntimeException {}
