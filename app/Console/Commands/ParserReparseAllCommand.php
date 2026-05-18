<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AggregatorSourceType;
use App\Models\AggregatorListing;
use App\Models\AggregatorSource;
use App\Models\ParserRun;
use App\Scrapers\Aggregator\ReparseAction;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Throwable;

/**
 * Re-runs the ParserPipeline across every AggregatorListing belonging to a
 * tenant, bumping each row's `parser_version` to the target_version pinned at
 * command start.
 *
 * Designed to be safe on millions of rows:
 *   - Iterates in id-asc chunks (default 1000) so the working set stays small.
 *   - One transaction per chunk, not per row, to keep WAL pressure bounded.
 *   - Skips rows that are already on the target version so reruns after
 *     interrupt are cheap.
 *   - On SIGINT / SIGTERM, finishes the current chunk and prints the resume
 *     command before exiting.
 *
 * The corresponding `parser:reparse --source=...` subcommand is the same
 * implementation with `--source` pre-filled for convenience.
 */
final class ParserReparseAllCommand extends Command
{
    protected $signature = 'parser:reparse-all
        {--tenant= : Tenant slug}
        {--force-corrections : Drop the correction overlay so the raw parser output wins}
        {--chunk=1000 : Rows per chunk (one DB transaction)}
        {--from= : Resume id (process rows with id >= this value)}
        {--source= : Filter by aggregator source type (hackernews, remotive, himalayas, weworkremotely, remoteok)}';

    protected $description = 'Re-run the parser pipeline against every aggregator listing for one tenant.';

    private bool $shouldStop = false;

    public function __construct(
        private readonly ReparseAction $reparseAction,
        private readonly Connection $db,
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

        $chunk = max(1, (int) $this->option('chunk'));
        $force = (bool) $this->option('force-corrections');
        $fromId = $this->option('from') === null ? 0 : max(0, (int) $this->option('from'));

        $sourceFilter = $this->resolveSourceFilter();
        if ($sourceFilter === false) {
            return self::FAILURE;
        }

        $sourceIds = $this->sourceIdsFor($sourceFilter);

        $targetVersion = $this->resolveTargetVersion($sourceIds, $sourceFilter?->value);

        $totalToProcess = $this->countPending($targetVersion, $fromId, $sourceIds);

        $run = ParserRun::query()->create([
            'target_version' => $targetVersion,
            'from_id' => $fromId,
            'processed' => 0,
            'force_corrections' => $force,
            'source_filter' => $sourceFilter?->value,
            'started_at' => CarbonImmutable::now(),
        ]);

        $this->registerSignalHandlers();

        $progressBar = $this->output->isQuiet() ? null : $this->output->createProgressBar($totalToProcess);
        $progressBar?->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %rate%/s');
        $progressBar?->start();

        $startedAt = CarbonImmutable::now();
        $processed = 0;
        $lastId = $fromId;

        while (! $this->shouldStop) {
            $listings = $this->loadNextChunk($targetVersion, $lastId, $chunk, $sourceIds);
            if ($listings === []) {
                break;
            }

            $this->db->transaction(function () use ($listings, $targetVersion, $force, &$processed, &$lastId): void {
                foreach ($listings as $listing) {
                    $this->reparseAction->reparseWithoutTransaction($listing, $force);
                    $listing->parser_version = $targetVersion;
                    $listing->save();
                    $processed++;
                    $lastId = (int) $listing->id;
                }
            });

            $progressBar?->setMessage($this->throughput($processed, $startedAt), 'rate');
            $progressBar?->advance(count($listings));

            // Refresh ParserRun row so external observers can see progress.
            $run->fill(['processed' => $processed])->save();
        }

        $finishedAt = CarbonImmutable::now();
        $completed = ! $this->shouldStop;

        $run->fill([
            'processed' => $processed,
            'to_id' => $completed ? $lastId : null,
            'finished_at' => $completed ? $finishedAt : null,
        ])->save();

        $progressBar?->finish();
        $this->newLine();

        $this->emitSummary($run, $tenant, $processed, $lastId, $startedAt, $finishedAt, $completed);

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

    /**
     * Returns:
     *   - AggregatorSourceType when --source was supplied with a known value.
     *   - null when --source was omitted (all sources).
     *   - false when --source was supplied with an unknown value (failure).
     */
    private function resolveSourceFilter(): AggregatorSourceType|null|false
    {
        $value = $this->option('source');
        if (! is_string($value) || $value === '') {
            return null;
        }

        $type = AggregatorSourceType::tryFrom($value);
        if (! $type instanceof AggregatorSourceType) {
            $this->error('Unknown source. Expected one of: '.implode(', ', array_map(static fn ($c) => $c->value, AggregatorSourceType::cases())));

            return false;
        }

        return $type;
    }

    /**
     * @return list<int>|null null means "no filter"; empty list means
     *                        "filter requested but no matching sources for
     *                        this tenant" (the iteration will yield zero rows).
     */
    private function sourceIdsFor(?AggregatorSourceType $type): ?array
    {
        if ($type === null) {
            return null;
        }

        return AggregatorSource::query()
            ->where('type', $type->value)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Target parser_version is pinned at command start so resumes land on the
     * same target as the killed run. Semantics:
     *
     *   - If any unfinished parser_run already exists for this tenant +
     *     source filter, adopt its target_version (resume mode).
     *   - Else if some rows are below the current max version, target = max
     *     so the pending bump completes.
     *   - Else target = max + 1 so a fresh full reparse bumps every row.
     *
     * Re-running back-to-back after a clean completion picks target = max+1
     * each time. Operators who want to make a no-change rerun a true no-op
     * should leave the prior parser_run unfinished... typically not desired;
     * the bump cost is small relative to the parse work itself.
     *
     * @param  list<int>|null  $sourceIds
     */
    private function resolveTargetVersion(?array $sourceIds, ?string $sourceFilter): int
    {
        $unfinished = ParserRun::query()
            ->whereNull('finished_at')
            ->where(function ($q) use ($sourceFilter): void {
                if ($sourceFilter === null) {
                    $q->whereNull('source_filter');
                } else {
                    $q->where('source_filter', $sourceFilter);
                }
            })
            ->orderByDesc('id')
            ->first();

        if ($unfinished instanceof ParserRun) {
            return (int) $unfinished->target_version;
        }

        $query = AggregatorListing::query();
        if ($sourceIds !== null) {
            $query->whereIn('source_id', $sourceIds);
        }
        $max = (int) ($query->max('parser_version') ?? 1);

        $hasPendingBelowMax = (clone $query)->where('parser_version', '<', $max)->exists();
        if ($hasPendingBelowMax) {
            return $max;
        }

        return $max + 1;
    }

    /**
     * @param  list<int>|null  $sourceIds
     */
    private function countPending(int $targetVersion, int $fromId, ?array $sourceIds): int
    {
        $query = AggregatorListing::query()
            ->where('id', '>=', $fromId)
            ->where('parser_version', '<', $targetVersion);
        if ($sourceIds !== null) {
            $query->whereIn('source_id', $sourceIds);
        }

        return $query->count();
    }

    /**
     * @param  list<int>|null  $sourceIds
     * @return list<AggregatorListing>
     */
    private function loadNextChunk(int $targetVersion, int $afterOrEqualId, int $chunk, ?array $sourceIds): array
    {
        $query = AggregatorListing::query()
            ->where('parser_version', '<', $targetVersion)
            ->where('id', '>=', $afterOrEqualId)
            ->orderBy('id')
            ->limit($chunk);
        if ($sourceIds !== null) {
            $query->whereIn('source_id', $sourceIds);
        }

        /** @var list<AggregatorListing> $rows */
        $rows = $query->get()->all();

        return $rows;
    }

    private function throughput(int $processed, CarbonImmutable $startedAt): string
    {
        $elapsedSeconds = max(0.001, CarbonImmutable::now()->diffInMicroseconds($startedAt, true) / 1_000_000);

        return (string) (int) round($processed / $elapsedSeconds);
    }

    private function registerSignalHandlers(): void
    {
        if (! function_exists('pcntl_signal')) {
            return;
        }

        pcntl_async_signals(true);
        pcntl_signal(SIGINT, function (): void {
            $this->shouldStop = true;
        });
        pcntl_signal(SIGTERM, function (): void {
            $this->shouldStop = true;
        });
    }

    private function emitSummary(
        ParserRun $run,
        Tenant $tenant,
        int $processed,
        int $lastId,
        CarbonImmutable $startedAt,
        CarbonImmutable $finishedAt,
        bool $completed,
    ): void {
        $payload = [
            'command' => 'parser:reparse-all',
            'tenant' => $tenant->slug,
            'parser_run_id' => (int) $run->id,
            'target_version' => (int) $run->target_version,
            'processed' => $processed,
            'last_id' => $lastId,
            'completed' => $completed,
            'source_filter' => $run->source_filter,
            'force_corrections' => (bool) $run->force_corrections,
            'started_at' => $startedAt->toIso8601String(),
            'finished_at' => $finishedAt->toIso8601String(),
            'duration_ms' => $finishedAt->diffInMilliseconds($startedAt, true),
        ];

        if (! $completed) {
            $payload['resume_command'] = sprintf(
                'php artisan parser:reparse-all --tenant=%s --from=%d',
                $tenant->slug,
                $lastId + 1,
            );
        }

        try {
            $this->line((string) json_encode($payload, JSON_THROW_ON_ERROR));
        } catch (Throwable) {
            // No-op: the run itself succeeded.
        }
    }
}
