<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ScrapeRun;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * Public, unauthenticated liveness probe at `/up`.
 *
 * Replaces Laravel's built-in `withRouting(health: '/up')` which only confirms
 * the framework boots and never touches the database. Per PRD §8.6 / Phase 6
 * item 12 this contract is stricter: the app is "live" only when
 *
 *   1. a PDO connection to the primary database can be opened, AND
 *   2. at least one ScrapeRun finished within
 *      `config('health.liveness_max_scrape_age_hours')` (default 48h).
 *
 * The scrape freshness check spans every tenant (the global tenant scope is
 * removed) because liveness is a fleet-wide signal, not a per-tenant one, and
 * runs before any tenant context is resolved.
 *
 * Returns 200 with `{"status":"up"}` when healthy, otherwise 503 with a terse
 * machine-readable reason so an orchestrator can act without parsing prose.
 */
final readonly class LivenessController
{
    public function __construct(
        private DatabaseManager $db,
        private ConfigRepository $config,
    ) {}

    public function __invoke(): JsonResponse
    {
        if (! $this->databaseReachable()) {
            return $this->down('database_unreachable');
        }

        if (! $this->recentScrapeExists()) {
            return $this->down('no_recent_scrape');
        }

        return new JsonResponse(['status' => 'up', 'reason' => null], 200);
    }

    private function databaseReachable(): bool
    {
        try {
            $this->db->connection()->getPdo();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function recentScrapeExists(): bool
    {
        $maxAgeHours = (int) $this->config->get('health.liveness_max_scrape_age_hours', 48);
        $threshold = CarbonImmutable::now()->subHours($maxAgeHours);

        try {
            return ScrapeRun::withoutTenantScope()
                ->whereNotNull('finished_at')
                ->where('finished_at', '>=', $threshold)
                ->exists();
        } catch (Throwable) {
            return false;
        }
    }

    private function down(string $reason): JsonResponse
    {
        return new JsonResponse(['status' => 'down', 'reason' => $reason], 503);
    }
}
