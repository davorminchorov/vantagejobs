<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Psr\Log\LoggerInterface;

/**
 * Phase 3 step 10 stub.
 *
 * Real implementation will iterate every aggregator_listing for the active
 * tenant, re-run the ParserPipeline against the stored `raw` payload, diff
 * the result, and update the `parsed` jsonb + denormalized columns. Step 10
 * fills this in. The /admin/parser dashboard's "Re-parse all" button posts
 * to ReparseAllController which dispatches this command, so the user-facing
 * confirmation flow is testable today.
 */
final class ParserReparseAllCommand extends Command
{
    protected $signature = 'parser:reparse-all
        {--tenant= : Tenant slug (required once step 10 lands)}';

    protected $description = '[stub] Re-parse every aggregator listing for the tenant. Implemented in step 10.';

    public function __construct(private readonly LoggerInterface $logger)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->logger->info('parser:reparse-all stub invoked', [
            'tenant' => $this->option('tenant'),
            'note' => 'TODO: implement in Phase 3 step 10',
        ]);

        $this->line((string) json_encode([
            'command' => 'parser:reparse-all',
            'status' => 'stub',
            'message' => 'TODO: implement in step 10',
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
