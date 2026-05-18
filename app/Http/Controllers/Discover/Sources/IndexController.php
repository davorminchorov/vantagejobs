<?php

declare(strict_types=1);

namespace App\Http\Controllers\Discover\Sources;

use App\Models\AggregatorSource;
use App\Models\DismissedCompany;
use Inertia\Inertia;
use Inertia\Response;

/**
 * GET /discover/sources. Two stacked sections:
 *
 *   1. Aggregator sources — enable / disable, "Run now", last-run +
 *      next-run timestamps + consecutive_failures + last_error.
 *   2. Auto-dismiss rules (PRD §6.4.4) — revoke or keep, with the
 *      bump count for context. The dashboard banner links here.
 */
final readonly class IndexController
{
    public function __invoke(): Response
    {
        $sources = AggregatorSource::query()
            ->orderBy('type')
            ->get(['id', 'type', 'name', 'enabled', 'cadence_minutes', 'last_run_at', 'next_run_at', 'last_error', 'consecutive_failures']);

        $rules = DismissedCompany::query()
            ->orderByDesc('auto_dismiss')
            ->orderByDesc('dismiss_count')
            ->orderBy('display_name')
            ->get(['id', 'normalized_company_key', 'display_name', 'dismiss_count', 'auto_dismiss', 'last_dismissed_at']);

        return Inertia::render('Discover/Sources/Index', [
            'sources' => $sources,
            'rules' => $rules,
        ]);
    }
}
