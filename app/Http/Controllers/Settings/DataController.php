<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Enums\ExportJobStatus;
use App\Models\ExportJob;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Renders the /settings/data page: a list of the tenant's recent export jobs
 * with status, and a download link for any bundle still inside its 24h window.
 */
final readonly class DataController
{
    public function __invoke(Request $request): Response
    {
        $now = CarbonImmutable::now();

        $exports = ExportJob::query()
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(static function (ExportJob $job) use ($now): array {
                $downloadable = $job->status === ExportJobStatus::COMPLETED
                    && $job->expires_at !== null
                    && $job->expires_at->isAfter($now);

                return [
                    'id' => (int) $job->getKey(),
                    'status' => $job->status->value,
                    'formats' => $job->formats ?? [],
                    'createdAt' => $job->created_at?->toIso8601String(),
                    'expiresAt' => $job->expires_at?->toIso8601String(),
                    'schemaVersion' => $job->schema_version,
                    'counts' => is_array($job->manifest) ? ($job->manifest['counts'] ?? null) : null,
                    'error' => $job->error,
                    'downloadable' => $downloadable,
                ];
            })
            ->all();

        return Inertia::render('Settings/Data', [
            'exports' => $exports,
        ]);
    }
}
