<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ScrapeRun;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * Auth-required rich health check at `/health`.
 *
 * Returns operator-grade JSON: database reachability, last finished scrape per
 * source (so a stalled source is one glance away), pending queue depth, and
 * free disk space on the storage volume. Tenant-scoped reads use the tenant
 * resolved by ResolveTenantContext; the public `/up` liveness probe
 * (LivenessController) handles fleet-wide gating instead.
 *
 * `status` is `ok` when the database is reachable, otherwise `degraded` with a
 * 503 so the route doubles as a deeper probe behind auth. Individual checks
 * surface their own `ok` flag for granular alerting.
 */
final readonly class HealthController
{
    public function __construct(
        private DatabaseManager $db,
        private ConfigRepository $config,
    ) {}

    public function __invoke(): JsonResponse
    {
        $database = $this->checkDatabase();

        $checks = [
            'database' => $database,
            'scrapes' => $this->checkScrapes(),
            'queue' => $this->checkQueue(),
            'disk' => $this->checkDisk(),
        ];

        $status = $database['ok'] ? 'ok' : 'degraded';

        return new JsonResponse([
            'status' => $status,
            'checks' => $checks,
        ], $database['ok'] ? 200 : 503);
    }

    /**
     * @return array{ok: bool, error: string|null}
     */
    private function checkDatabase(): array
    {
        try {
            $this->db->connection()->getPdo();

            return ['ok' => true, 'error' => null];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Most-recent finished scrape per source, flagged stale when older than the
     * configured liveness window. Tenant-scoped via the global scope.
     *
     * @return array<string, mixed>
     */
    private function checkScrapes(): array
    {
        $maxAgeHours = (int) $this->config->get('health.liveness_max_scrape_age_hours', 48);

        try {
            $rows = ScrapeRun::query()
                ->select(['source', 'source_kind'])
                ->selectRaw('MAX(finished_at) AS last_finished_at')
                ->whereNotNull('finished_at')
                ->groupBy('source', 'source_kind')
                ->orderBy('source_kind')
                ->orderBy('source')
                ->get();
        } catch (Throwable $e) {
            return ['ok' => false, 'max_age_hours' => $maxAgeHours, 'sources' => [], 'error' => $e->getMessage()];
        }

        $thresholdTimestamp = now()->subHours($maxAgeHours)->getTimestamp();
        $anyFresh = false;
        $sources = [];

        foreach ($rows as $row) {
            $lastFinished = $row->getAttribute('last_finished_at');
            $lastFinishedAt = $lastFinished !== null ? (string) $lastFinished : null;
            $stale = true;

            if ($lastFinished !== null) {
                $parsed = strtotime((string) $lastFinished);
                $stale = $parsed === false || $parsed < $thresholdTimestamp;
            }

            if (! $stale) {
                $anyFresh = true;
            }

            $sources[] = [
                'source' => (string) $row->source,
                'source_kind' => (string) $row->source_kind,
                'last_finished_at' => $lastFinishedAt,
                'stale' => $stale,
            ];
        }

        return [
            'ok' => $anyFresh,
            'max_age_hours' => $maxAgeHours,
            'sources' => $sources,
        ];
    }

    /**
     * Pending queue depth from the jobs table. A fast-growing depth signals a
     * stalled worker. Tolerant of a non-database queue driver.
     *
     * @return array{ok: bool, pending: int|null, error: string|null}
     */
    private function checkQueue(): array
    {
        try {
            $pending = (int) $this->db->connection()->table('jobs')->count();

            return ['ok' => true, 'pending' => $pending, 'error' => null];
        } catch (Throwable $e) {
            return ['ok' => true, 'pending' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * Free / total bytes on the storage volume.
     *
     * @return array{ok: bool, free_bytes: int|null, total_bytes: int|null, free_percent: float|null}
     */
    private function checkDisk(): array
    {
        $path = storage_path();
        $free = @disk_free_space($path);
        $total = @disk_total_space($path);

        if ($free === false || $total === false || $total <= 0) {
            return ['ok' => true, 'free_bytes' => null, 'total_bytes' => null, 'free_percent' => null];
        }

        $freePercent = round(($free / $total) * 100, 1);

        return [
            'ok' => $freePercent >= 5.0,
            'free_bytes' => (int) $free,
            'total_bytes' => (int) $total,
            'free_percent' => $freePercent,
        ];
    }
}
