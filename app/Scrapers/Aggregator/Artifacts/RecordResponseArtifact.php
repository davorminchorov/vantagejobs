<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\Artifacts;

use App\Models\ScrapeRunArtifact;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Psr\Log\LoggerInterface;
use Saloon\Http\Response;
use Throwable;

/**
 * Saloon response middleware: persists every raw HTTP response body to the
 * local filesystem (gzipped) and records a scrape_run_artifacts row with the
 * link back to the scrape_runs row that triggered it.
 *
 * Filesystem layout (per PRD §6.1.6):
 *   storage/scrape-artifacts/{yyyy-mm-dd}/{source-slug}/{connector}/
 *     {run_id-or-"orphan"}_{sequence}_{short-sha}.{ext}.gz
 *
 * Retention is config('scrapers.artifact_retention_days') days (default 7);
 * the scrape-artifacts:purge command sweeps expired rows + files daily.
 *
 * Failure handling: every write path is wrapped — a failed artifact MUST NOT
 * surface as a request failure to the scraper. Errors get logged via the
 * injected LoggerInterface and the request continues unchanged.
 */
final readonly class RecordResponseArtifact
{
    public function __construct(
        private FilesystemFactory $filesystem,
        private LoggerInterface $logger,
        private ScrapeArtifactContext $context,
    ) {}

    /**
     * Saloon calls this for each completed response on connectors that have
     * registered the middleware via `$this->middleware()->onResponse(...)`.
     */
    public function __invoke(Response $response): Response
    {
        try {
            $this->record($response);
        } catch (Throwable $e) {
            $this->logger->warning('scrape-artifact: write failed', [
                'message' => $e->getMessage(),
                'connector' => $this->connectorSlug($response),
            ]);
        }

        return $response;
    }

    private function record(Response $response): void
    {
        $now = CarbonImmutable::now();
        $body = $response->body();
        $gzipped = gzencode($body, 6);

        if ($gzipped === false) {
            throw new \RuntimeException('gzencode returned false');
        }

        $sha = hash('sha256', $gzipped);
        $connector = $this->connectorSlug($response);
        $extension = $this->extensionFor($response->header('Content-Type'));

        $runIdSegment = $this->context->scrapeRunId() === null
            ? 'orphan'
            : (string) $this->context->scrapeRunId();

        // Sequence based on microsecond timestamp keeps multiple captures in
        // one run distinct without a database round-trip for a counter.
        $sequence = (string) (int) ($now->getPreciseTimestamp(3) % 100_000_000);
        $shortSha = substr($sha, 0, 12);

        $relativePath = sprintf(
            'scrape-artifacts/%s/%s/%s/%s_%s_%s.%s.gz',
            $now->toDateString(),
            $this->sourceSlug(),
            $connector,
            $runIdSegment,
            $sequence,
            $shortSha,
            $extension,
        );

        $disk = $this->filesystem->disk('local');
        $disk->put($relativePath, $gzipped);

        $retentionDays = (int) config('scrapers.artifact_retention_days', 7);

        ScrapeRunArtifact::query()->create([
            'tenant_id' => $this->context->tenantId(),
            'scrape_run_id' => $this->context->scrapeRunId(),
            'source_kind' => $this->context->sourceKind(),
            'source_id' => $this->context->sourceId(),
            'company_id' => $this->context->companyId(),
            'connector' => $connector,
            'request_endpoint' => $response->getPendingRequest()->getUrl(),
            'http_status' => $response->status(),
            'path' => $relativePath,
            'size_bytes' => strlen($gzipped),
            'sha256' => $sha,
            'retained_until' => $now->addDays($retentionDays),
            'fetched_at' => $now,
        ]);
    }

    private function connectorSlug(Response $response): string
    {
        $connectorClass = $response->getPendingRequest()->getConnector()::class;

        // App\Scrapers\Aggregator\RemoteOk\RemoteOkConnector -> "remoteok"
        // App\Scrapers\Aggregator\HackerNews\HackerNewsAlgoliaConnector -> "hackernews_algolia"
        $short = (string) preg_replace('/^.*\\\\/', '', $connectorClass);
        $short = (string) preg_replace('/Connector$/', '', $short);
        $snake = (string) preg_replace_callback(
            '/[A-Z]/',
            static fn (array $m): string => '_'.strtolower($m[0]),
            $short,
        );

        return ltrim($snake, '_');
    }

    private function sourceSlug(): string
    {
        return match ($this->context->sourceKind()) {
            'ats' => 'ats',
            default => 'aggregator',
        };
    }

    private function extensionFor(string|array|null $contentType): string
    {
        if (is_array($contentType)) {
            $contentType = $contentType[0] ?? null;
        }

        $type = strtolower((string) $contentType);

        return match (true) {
            str_contains($type, 'application/json') => 'json',
            str_contains($type, 'application/xml'),
            str_contains($type, 'text/xml') => 'xml',
            str_contains($type, 'text/html') => 'html',
            default => 'bin',
        };
    }
}
