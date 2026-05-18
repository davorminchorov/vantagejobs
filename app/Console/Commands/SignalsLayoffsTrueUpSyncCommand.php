<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Signals\Layoffs\LayoffsSync;
use App\Signals\Layoffs\TrueUpClient;
use App\Signals\Layoffs\TrueUpHtmlParser;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Throwable;

/**
 * Weekly TrueUp layoffs sync. SOURCES.md §4.2 — fallback for layoffs.fyi.
 *
 * Defaults to fetching live HTML; `--source=local --path=/path/to/page.html`
 * is provided for fixture-based reruns when the live page changes shape.
 */
final class SignalsLayoffsTrueUpSyncCommand extends Command
{
    protected $signature = 'signals:layoffs:trueup
        {--tenant= : Tenant slug}
        {--source=remote : remote|local}
        {--path= : Path to HTML when --source=local}';

    protected $description = 'Cross-reference TrueUp layoffs against tracked companies and emit layoff events.';

    public function __construct(
        private readonly Filesystem $files,
        private readonly TrueUpClient $client,
        private readonly TrueUpHtmlParser $parser,
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

        $html = $this->loadHtml();
        if ($html === null) {
            return self::FAILURE;
        }

        $startedAt = CarbonImmutable::now('UTC');
        $records = $this->parser->parse($html);
        $counters = $this->sync->sync($records);
        $finishedAt = CarbonImmutable::now('UTC');

        $this->line((string) json_encode([
            'command' => 'signals:layoffs:trueup',
            'tenant' => $tenant->slug,
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

    private function loadHtml(): ?string
    {
        $source = (string) $this->option('source');

        if ($source === 'local') {
            $path = $this->option('path');
            if (! is_string($path) || $path === '') {
                $this->error('--source=local requires --path=/path/to/page.html');

                return null;
            }
            if (! $this->files->exists($path)) {
                $this->error("HTML file not found at [{$path}].");

                return null;
            }

            return $this->files->get($path);
        }

        if ($source !== 'remote') {
            $this->error("Unknown --source [{$source}]. Use 'remote' or 'local'.");

            return null;
        }

        try {
            return $this->client->fetch();
        } catch (Throwable $e) {
            $this->error('TrueUp fetch failed: '.$e->getMessage());

            return null;
        }
    }
}
