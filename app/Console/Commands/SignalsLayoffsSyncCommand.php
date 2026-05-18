<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Signals\Layoffs\LayoffsCsvParser;
use App\Signals\Layoffs\LayoffsSync;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

/**
 * Daily-ish layoffs cross-reference. PRD §6.3.2, SOURCES §4.1 (layoffs.fyi
 * Kaggle mirror `theakhilb/layoffs-data-2022`).
 *
 * --source=local --path=/path/to/file.csv reads from disk. This is the only
 * supported source today: PRD calls layoffs.fyi "mildly hostile to consume"
 * and the Kaggle download path needs API credentials + an http roundtrip
 * we haven't wired yet. Operators download the CSV by hand, drop it on the
 * server, and point this command at it. Auto-Kaggle is a follow-up.
 */
final class SignalsLayoffsSyncCommand extends Command
{
    protected $signature = 'signals:layoffs:sync
        {--tenant= : Tenant slug}
        {--source=local : local|kaggle (kaggle is not yet implemented)}
        {--path= : Path to CSV when --source=local}';

    protected $description = 'Cross-reference layoffs.fyi records against tracked companies and emit layoff events.';

    public function __construct(
        private readonly Filesystem $files,
        private readonly LayoffsCsvParser $parser,
        private readonly LayoffsSync $sync,
    ) {
        parent::__construct();
    }

    public function handle(TenantContext $context): int
    {
        $tenant = $this->resolveTenant();
        if (! $tenant instanceof Tenant) {
            return self::FAILURE;
        }

        $context->setCurrent($tenant);

        $source = (string) $this->option('source');
        $csv = $this->loadCsv($source);
        if ($csv === null) {
            return self::FAILURE;
        }

        $startedAt = CarbonImmutable::now('UTC');
        $records = $this->parser->parse($csv);
        $counters = $this->sync->sync($records);
        $finishedAt = CarbonImmutable::now('UTC');

        $this->line((string) json_encode([
            'command' => 'signals:layoffs:sync',
            'tenant' => $tenant->slug,
            'source' => $source,
            'parsed' => count($records),
            'matched' => $counters['matched'],
            'created' => $counters['created'],
            'duplicate' => $counters['duplicate'],
            'unmatched' => $counters['unmatched'],
            'duration_ms' => $finishedAt->diffInMilliseconds($startedAt, true),
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    private function resolveTenant(): ?Tenant
    {
        $slug = $this->option('tenant');
        if (! is_string($slug) || $slug === '') {
            $this->error('The --tenant=<slug> option is required.');

            return null;
        }

        /** @var Tenant|null $tenant */
        $tenant = Tenant::query()->where('slug', $slug)->first();
        if (! $tenant instanceof Tenant) {
            $this->error("Tenant [{$slug}] not found.");

            return null;
        }

        return $tenant;
    }

    private function loadCsv(string $source): ?string
    {
        if ($source === 'kaggle') {
            // TODO(phase-4-followup): pull the Kaggle dataset
            // `theakhilb/layoffs-data-2022` via the Kaggle API. Needs
            // KAGGLE_USERNAME + KAGGLE_KEY env wiring and a small Saloon
            // connector. For now operators run with --source=local.
            $this->error('--source=kaggle is not yet implemented; set --source=local --path=... ; auto-Kaggle in follow-up.');

            return null;
        }

        if ($source !== 'local') {
            $this->error("Unknown --source [{$source}]. Use 'local' or 'kaggle'.");

            return null;
        }

        $path = $this->option('path');
        if (! is_string($path) || $path === '') {
            $this->error('--source=local requires --path=/path/to/file.csv');

            return null;
        }

        if (! $this->files->exists($path)) {
            $this->error("CSV not found at [{$path}].");

            return null;
        }

        return $this->files->get($path);
    }
}
