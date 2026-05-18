<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AggregatorSource;
use App\Models\ScrapeRun;
use App\Scrapers\Aggregator\AggregatorOrchestrator;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

final class AggregatorRunAllCommand extends Command
{
    protected $signature = 'aggregator:run-all
        {--tenant= : Tenant slug}
        {--include-disabled : Run even sources where enabled=false}';

    protected $description = 'Run every enabled aggregator scraper for one tenant. JSON summary on stdout.';

    public function handle(TenantContext $context, AggregatorOrchestrator $orchestrator): int
    {
        $tenant = $this->resolveTenant();
        if (! $tenant instanceof Tenant) {
            return self::FAILURE;
        }

        $context->setCurrent($tenant);

        $startedAt = CarbonImmutable::now();

        $query = AggregatorSource::query();
        if (! $this->option('include-disabled')) {
            $query->where('enabled', true);
        }
        $sources = $query->orderBy('type')->get();

        $runs = [];
        foreach ($sources as $source) {
            $run = $orchestrator->runForSource($source);
            $runs[] = [
                'source' => $source->type->value,
                ...$this->describeRun($run),
            ];
        }

        $finishedAt = CarbonImmutable::now();

        $this->line((string) json_encode([
            'command' => 'aggregator:run-all',
            'tenant' => $tenant->slug,
            'started_at' => $startedAt->toIso8601String(),
            'finished_at' => $finishedAt->toIso8601String(),
            'duration_ms' => $finishedAt->diffInMilliseconds($startedAt, true),
            'totals' => $this->totals($runs),
            'runs' => $runs,
        ], JSON_THROW_ON_ERROR));

        return $this->anyErrors($runs) ? self::FAILURE : self::SUCCESS;
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

    /**
     * @return array<string, mixed>
     */
    private function describeRun(ScrapeRun $run): array
    {
        return [
            'status' => $run->status,
            'fetched' => $run->fetched,
            'new' => $run->new_count,
            'errors' => $run->errors ?? [],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $runs
     * @return array<string, int>
     */
    private function totals(array $runs): array
    {
        $totals = [
            'sources' => count($runs),
            'completed' => 0,
            'partial' => 0,
            'errors' => 0,
            'new_listings' => 0,
        ];

        foreach ($runs as $run) {
            match ($run['status']) {
                'completed' => $totals['completed']++,
                'partial' => $totals['partial']++,
                'error' => $totals['errors']++,
                default => null,
            };
            $totals['new_listings'] += (int) $run['new'];
        }

        return $totals;
    }

    /**
     * @param  list<array<string, mixed>>  $runs
     */
    private function anyErrors(array $runs): bool
    {
        foreach ($runs as $run) {
            if ($run['status'] === 'error') {
                return true;
            }
        }

        return false;
    }
}
