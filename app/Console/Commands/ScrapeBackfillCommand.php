<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AggregatorSourceType;
use App\Models\AggregatorSource;
use App\Scrapers\Aggregator\ScrapeBackfillAction;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * scrape:backfill --source=<type> --days=N
 *
 * Per PRD §6.1.7. Two paths, picked by source kind:
 *
 *   - HackerNews has real history via Algolia; we re-pull the last N monthly
 *     "Who is Hiring" threads and run each through the existing scrape path
 *     (idempotent on `(source_id, external_id)`).
 *   - Remotive / Himalayas / WeWorkRemotely / RemoteOK are realtime-only;
 *     for these we replay raw HTTP responses captured by Saloon's
 *     RecordResponseArtifact middleware from `scrape_run_artifacts`. Days
 *     older than the retention window (default 7) are reported as
 *     out_of_retention.
 *
 * Output: per-source summary JSON line.
 */
final class ScrapeBackfillCommand extends Command
{
    protected $signature = 'scrape:backfill
        {--tenant= : Tenant slug}
        {--source= : Aggregator source type (hackernews, remotive, himalayas, weworkremotely, remoteok). Required.}
        {--days=7 : Number of days (months for HN) of history to backfill}';

    protected $description = 'Backfill aggregator listings: HN re-pulls past monthly threads, others replay retained artifacts.';

    public function __construct(private readonly ScrapeBackfillAction $action)
    {
        parent::__construct();
    }

    public function handle(TenantContext $context): int
    {
        $tenant = $this->resolveTenant();
        if (! $tenant instanceof Tenant) {
            return self::FAILURE;
        }

        $context->setCurrent($tenant);

        $type = $this->resolveSourceType();
        if (! $type instanceof AggregatorSourceType) {
            return self::FAILURE;
        }

        $days = max(1, (int) $this->option('days'));

        $source = AggregatorSource::query()->where('type', $type->value)->first();
        if (! $source instanceof AggregatorSource) {
            $this->error("Aggregator source [{$type->value}] not found for tenant [{$tenant->slug}]. Seed it first.");

            return self::FAILURE;
        }

        $startedAt = CarbonImmutable::now();
        $result = $this->action->backfill($source, $days);
        $finishedAt = CarbonImmutable::now();

        $payload = array_merge(
            ['command' => 'scrape:backfill', 'tenant' => $tenant->slug],
            $result->toArray(),
            ['duration_ms' => $finishedAt->diffInMilliseconds($startedAt, true)],
        );

        $this->line((string) json_encode($payload, JSON_THROW_ON_ERROR));

        return $result->errors === [] ? self::SUCCESS : self::SUCCESS;
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

    private function resolveSourceType(): ?AggregatorSourceType
    {
        $value = $this->option('source');
        if (! is_string($value) || $value === '') {
            $this->error('The --source=<type> option is required.');

            return null;
        }

        $type = AggregatorSourceType::tryFrom($value);
        if (! $type instanceof AggregatorSourceType) {
            $this->error('Unknown source. Expected one of: '.implode(', ', array_map(static fn ($c) => $c->value, AggregatorSourceType::cases())));

            return null;
        }

        return $type;
    }
}
