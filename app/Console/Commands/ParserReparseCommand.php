<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;

/**
 * Convenience alias for `parser:reparse-all --source=<type>`.
 *
 * Forwards every flag to parser:reparse-all so the operator can type the more
 * natural "reparse one source" form. --source is required here; without it the
 * caller should use parser:reparse-all directly.
 */
final class ParserReparseCommand extends Command
{
    protected $signature = 'parser:reparse
        {--tenant= : Tenant slug}
        {--source= : Aggregator source type (hackernews, remotive, himalayas, weworkremotely, remoteok). Required.}
        {--force-corrections : Drop the correction overlay so the raw parser output wins}
        {--chunk=1000 : Rows per chunk (one DB transaction)}
        {--from= : Resume id (process rows with id >= this value)}';

    protected $description = 'Re-run the parser pipeline against listings of one aggregator source for one tenant.';

    public function handle(ConsoleKernel $kernel): int
    {
        $source = $this->option('source');
        if (! is_string($source) || $source === '') {
            $this->error('The --source=<type> option is required for parser:reparse. Use parser:reparse-all for cross-source reparsing.');

            return self::FAILURE;
        }

        $arguments = [
            '--source' => $source,
        ];
        foreach (['tenant', 'chunk', 'from'] as $option) {
            $value = $this->option($option);
            if ($value !== null && $value !== '') {
                $arguments["--{$option}"] = $value;
            }
        }
        if ((bool) $this->option('force-corrections')) {
            $arguments['--force-corrections'] = true;
        }

        return $kernel->call('parser:reparse-all', $arguments, $this->output);
    }
}
