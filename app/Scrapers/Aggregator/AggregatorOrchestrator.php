<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator;

use App\Models\AggregatorSource;
use App\Models\ScrapeRun;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Throwable;

/**
 * Drives one aggregator scraper end-to-end against one source: opens a
 * scrape_runs row (source_kind = 'aggregator', company_id = null), resolves
 * the scraper from AggregatorRegistry, runs it, captures counts + errors,
 * closes the row, and updates the source's run-state bookkeeping.
 *
 * Sequential within a single call. The /discover/sources admin and the
 * scheduler invoke this once per source.
 */
final class AggregatorOrchestrator
{
    public function __construct(private readonly AggregatorRegistry $registry) {}

    public function runForSource(AggregatorSource $source): ScrapeRun
    {
        $now = CarbonImmutable::now();

        $run = ScrapeRun::query()->create([
            'company_id' => null,
            'source' => $source->type->value,
            'source_kind' => 'aggregator',
            'status' => 'running',
            'started_at' => $now,
        ]);

        try {
            $scraper = $this->registry->resolve($source->type);
        } catch (InvalidArgumentException $e) {
            $this->recordFailure($source, $e->getMessage());

            return $this->finalize($run, status: 'error', errors: [['message' => $e->getMessage()]]);
        }

        try {
            $result = $scraper->run($source);
        } catch (Throwable $e) {
            $this->recordFailure($source, $e->getMessage());

            return $this->finalize($run, status: 'error', errors: [['message' => $e->getMessage()]]);
        }

        $this->recordSuccess($source);

        return $this->finalize(
            $run,
            status: $result->hasErrors() ? 'partial' : 'completed',
            fetched: $result->fetched,
            newCount: $result->newCount,
            errors: $result->errors,
        );
    }

    private function recordSuccess(AggregatorSource $source): void
    {
        $now = CarbonImmutable::now();

        $source->fill([
            'last_run_at' => $now,
            'next_run_at' => $now->addMinutes($source->cadence_minutes),
            'last_error' => null,
            'consecutive_failures' => 0,
        ])->save();
    }

    private function recordFailure(AggregatorSource $source, string $message): void
    {
        $now = CarbonImmutable::now();

        $source->fill([
            'last_run_at' => $now,
            'next_run_at' => $now->addMinutes($source->cadence_minutes),
            'last_error' => $message,
            'consecutive_failures' => $source->consecutive_failures + 1,
        ])->save();
    }

    /**
     * @param  list<array{message: string, context?: array<string, mixed>}>  $errors
     */
    private function finalize(
        ScrapeRun $run,
        string $status,
        int $fetched = 0,
        int $newCount = 0,
        array $errors = [],
    ): ScrapeRun {
        $run->fill([
            'status' => $status,
            'finished_at' => CarbonImmutable::now(),
            'fetched' => $fetched,
            'new_count' => $newCount,
            'closed_count' => 0,
            'errors' => $errors === [] ? null : $errors,
        ])->save();

        return $run->refresh();
    }
}
