<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator;

use App\Models\AggregatorSource;
use App\Models\ScrapeRun;
use App\Scrapers\Aggregator\Artifacts\ScrapeArtifactContext;
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
    public function __construct(
        private readonly AggregatorRegistry $registry,
        private readonly ScrapeArtifactContext $artifactContext,
    ) {}

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

        $this->artifactContext->set(
            scrapeRunId: (int) $run->id,
            tenantId: $run->tenant_id === null ? null : (int) $run->tenant_id,
            sourceKind: 'aggregator',
            sourceId: (int) $source->id,
            companyId: null,
        );

        try {
            $result = $scraper->run($source);
        } catch (Throwable $e) {
            $this->recordFailure($source, $e->getMessage());

            return $this->finalize($run, status: 'error', errors: [['message' => $e->getMessage()]]);
        } finally {
            $this->artifactContext->clear();
        }

        if ($result->hasErrors()) {
            $this->recordPartial($source, $result->errors);
        } else {
            $this->recordSuccess($source);
        }

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

    /**
     * A partial run (fetched something, but hit per-item or per-page
     * errors) still advances the cadence clock, but must not report the
     * source as healthy the way recordSuccess() does — clearing last_error
     * and zeroing consecutive_failures on a run that had errors makes a
     * source that half-fails every day look clean forever. Also does not
     * touch consecutive_failures on the failure side: recordFailure() owns
     * incrementing that counter for hard (whole-run) failures only.
     *
     * @param  list<array{message: string, context?: array<string, mixed>}>  $errors
     */
    private function recordPartial(AggregatorSource $source, array $errors): void
    {
        $now = CarbonImmutable::now();
        $firstMessage = $errors[0]['message'] ?? null;

        $source->fill([
            'last_run_at' => $now,
            'next_run_at' => $now->addMinutes($source->cadence_minutes),
            'last_error' => is_string($firstMessage) ? $firstMessage : 'partial run with unspecified error',
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
