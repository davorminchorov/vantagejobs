<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Runs;

use App\Enums\AggregatorSourceType;
use App\Models\AggregatorSource;
use App\Models\Company;
use App\Models\ScrapeRun;
use App\Models\ScrapeRunArtifact;
use Carbon\CarbonImmutable;
use Inertia\Inertia;
use Inertia\Response;

/**
 * GET /admin/runs/{run} — drill-in for a single scrape run.
 *
 * Surfaces the run summary, the structured error log captured into
 * scrape_runs.errors (jsonb), and every retained scrape_run_artifacts row
 * keyed to this run. Expired artifacts (retained_until in the past) render
 * with an "expired" badge instead of a download link.
 */
final readonly class ShowController
{
    public function __invoke(int $run): Response
    {
        $scrapeRun = ScrapeRun::query()->findOrFail($run);

        $duration = null;
        if ($scrapeRun->started_at !== null && $scrapeRun->finished_at !== null) {
            $duration = (int) $scrapeRun->finished_at->diffInSeconds($scrapeRun->started_at, true);
        }

        $sourceLabel = $scrapeRun->source;
        if ($scrapeRun->source_kind === 'ats' && $scrapeRun->company_id !== null) {
            $sourceLabel = (string) (Company::query()->where('id', $scrapeRun->company_id)->value('name') ?? $sourceLabel);
        } elseif ($scrapeRun->source_kind === 'aggregator') {
            $aggSource = AggregatorSource::query()->where('type', $scrapeRun->source)->value('name');
            $sourceLabel = (string) ($aggSource ?? (AggregatorSourceType::tryFrom((string) $scrapeRun->source)?->displayName() ?? $sourceLabel));
        }

        $errors = $this->normalizeErrors($scrapeRun->errors);

        $now = CarbonImmutable::now();

        $artifacts = ScrapeRunArtifact::query()
            ->where('scrape_run_id', $scrapeRun->id)
            ->orderByDesc('fetched_at')
            ->get();

        $artifactItems = $artifacts
            ->map(static function (ScrapeRunArtifact $artifact) use ($now): array {
                $expired = $artifact->retained_until !== null && $artifact->retained_until->lt($now);

                return [
                    'id' => (int) $artifact->id,
                    'connector' => (string) $artifact->connector,
                    'request_endpoint' => (string) $artifact->request_endpoint,
                    'http_status' => $artifact->http_status === null ? null : (int) $artifact->http_status,
                    'size_bytes' => (int) $artifact->size_bytes,
                    'sha256' => (string) $artifact->sha256,
                    'fetched_at' => $artifact->fetched_at?->toIso8601String(),
                    'retained_until' => $artifact->retained_until?->toIso8601String(),
                    'expired' => $expired,
                    'download_url' => $expired
                        ? null
                        : route('admin.runs.artifacts.download', ['artifact' => $artifact->id]),
                ];
            })
            ->all();

        return Inertia::render('Admin/Runs/Show', [
            'run' => [
                'id' => (int) $scrapeRun->id,
                'started_at' => $scrapeRun->started_at?->toIso8601String(),
                'finished_at' => $scrapeRun->finished_at?->toIso8601String(),
                'duration_seconds' => $duration,
                'source_kind' => (string) $scrapeRun->source_kind,
                'source' => (string) $scrapeRun->source,
                'source_label' => $sourceLabel,
                'company_id' => $scrapeRun->company_id === null ? null : (int) $scrapeRun->company_id,
                'status' => (string) $scrapeRun->status,
                'fetched' => (int) $scrapeRun->fetched,
                'new_count' => (int) $scrapeRun->new_count,
                'closed_count' => (int) $scrapeRun->closed_count,
                'error_count' => count($errors),
            ],
            'errors' => $errors,
            'artifacts' => $artifactItems,
        ]);
    }

    /**
     * @param  mixed  $raw
     * @return list<array{message: string, context: ?array<string, mixed>}>
     */
    private function normalizeErrors(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $entry) {
            if (! is_array($entry)) {
                $out[] = ['message' => (string) $entry, 'context' => null];

                continue;
            }

            $message = $entry['message'] ?? null;
            $context = $entry['context'] ?? null;
            $out[] = [
                'message' => is_string($message) ? $message : (json_encode($entry, JSON_UNESCAPED_SLASHES) ?: ''),
                'context' => is_array($context) ? $context : null,
            ];
        }

        return $out;
    }
}
