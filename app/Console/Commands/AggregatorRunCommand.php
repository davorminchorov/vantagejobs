<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AggregatorSourceType;
use App\Models\AggregatorSource;
use App\Models\ScrapeRun;
use App\Scrapers\Aggregator\AggregatorOrchestrator;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

final class AggregatorRunCommand extends Command
{
    protected $signature = 'aggregator:run
        {source : Aggregator source type (hackernews, remotive, himalayas, weworkremotely, remoteok)}
        {--tenant= : Tenant slug}';

    protected $description = 'Run a single aggregator scraper for one tenant. JSON summary on stdout.';

    public function handle(TenantContext $context, AggregatorOrchestrator $orchestrator): int
    {
        $tenant = $this->resolveTenant();
        if (! $tenant instanceof Tenant) {
            return self::FAILURE;
        }

        $context->setCurrent($tenant);

        $type = AggregatorSourceType::tryFrom((string) $this->argument('source'));
        if (! $type instanceof AggregatorSourceType) {
            $this->error('Unknown source. Expected one of: '.implode(', ', array_map(static fn ($c) => $c->value, AggregatorSourceType::cases())));

            return self::FAILURE;
        }

        $source = AggregatorSource::query()->where('type', $type->value)->first();
        if (! $source instanceof AggregatorSource) {
            $this->error("Aggregator source [{$type->value}] not found for tenant [{$tenant->slug}]. Seed it first.");

            return self::FAILURE;
        }

        $startedAt = CarbonImmutable::now();
        $run = $orchestrator->runForSource($source);
        $finishedAt = CarbonImmutable::now();

        $this->line((string) json_encode([
            'command' => 'aggregator:run',
            'tenant' => $tenant->slug,
            'source' => $type->value,
            'started_at' => $startedAt->toIso8601String(),
            'finished_at' => $finishedAt->toIso8601String(),
            'duration_ms' => $finishedAt->diffInMilliseconds($startedAt, true),
            'run' => $this->describe($run),
        ], JSON_THROW_ON_ERROR));

        return $run->status === 'error' ? self::FAILURE : self::SUCCESS;
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
    private function describe(ScrapeRun $run): array
    {
        return [
            'status' => $run->status,
            'fetched' => $run->fetched,
            'new' => $run->new_count,
            'errors' => $run->errors ?? [],
        ];
    }
}
