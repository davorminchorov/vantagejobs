<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Database\DatabaseManager;
use Illuminate\Http\JsonResponse;
use Throwable;

final readonly class HealthController
{
    public function __construct(private DatabaseManager $db) {}

    /**
     * Auth-required health check. Returns rich JSON for operator inspection.
     *
     * Public `/up` liveness is provided by Laravel's built-in
     * `withRouting(health: '/up')` and does NOT touch the database —
     * that contract is preserved per PRD §8.6.
     */
    public function __invoke(): JsonResponse
    {
        $db = $this->checkDatabase();

        $status = $db['ok'] ? 'ok' : 'degraded';

        return new JsonResponse([
            'status' => $status,
            'checks' => [
                'database' => $db,
            ],
        ], $db['ok'] ? 200 : 503);
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
}
