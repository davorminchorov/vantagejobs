<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\Artifacts;

/**
 * Request-scoped carrier for the scrape run currently in flight. Set by the
 * orchestrator (aggregator or ATS) before invoking a scraper, cleared after.
 * The RecordResponseArtifact middleware reads from this when writing rows so
 * each artifact links back to the run + source + company that triggered it.
 *
 * When unset (e.g., ad-hoc tinker call), the middleware still writes the
 * artifact with null scrape_run_id ("orphan"). The retention sweep handles
 * those just like any other row.
 *
 * Container-scoped via AggregatorServiceProvider so Octane resets state
 * between requests instead of bleeding context across runs.
 */
final class ScrapeArtifactContext
{
    /**
     * 'ats' | 'aggregator'. Defaults to aggregator since this is the Phase 2/3
     * concern; the orchestrator overrides when wired in.
     */
    private string $sourceKind = 'aggregator';

    private ?int $scrapeRunId = null;

    private ?int $tenantId = null;

    private ?int $sourceId = null;

    private ?int $companyId = null;

    public function set(
        ?int $scrapeRunId,
        ?int $tenantId,
        string $sourceKind = 'aggregator',
        ?int $sourceId = null,
        ?int $companyId = null,
    ): void {
        $this->scrapeRunId = $scrapeRunId;
        $this->tenantId = $tenantId;
        $this->sourceKind = $sourceKind;
        $this->sourceId = $sourceId;
        $this->companyId = $companyId;
    }

    public function clear(): void
    {
        $this->scrapeRunId = null;
        $this->tenantId = null;
        $this->sourceKind = 'aggregator';
        $this->sourceId = null;
        $this->companyId = null;
    }

    public function scrapeRunId(): ?int
    {
        return $this->scrapeRunId;
    }

    public function tenantId(): ?int
    {
        return $this->tenantId;
    }

    public function sourceKind(): string
    {
        return $this->sourceKind;
    }

    public function sourceId(): ?int
    {
        return $this->sourceId;
    }

    public function companyId(): ?int
    {
        return $this->companyId;
    }
}
