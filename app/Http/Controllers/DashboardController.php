<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Company;
use App\Scrapers\Aggregator\ActiveAutoDismissRulesQuery;
use App\Sources\SourceHealthQuery;
use Inertia\Inertia;
use Inertia\Response;

final readonly class DashboardController
{
    public function __construct(
        private ActiveAutoDismissRulesQuery $autoDismissRules,
        private SourceHealthQuery $sourceHealth,
    ) {}

    public function __invoke(): Response
    {
        $companies = Company::query()
            ->orderBy('tier')
            ->orderBy('name')
            ->get(['id', 'name', 'website', 'ats_type', 'tier', 'status', 'last_checked_at']);

        return Inertia::render('Dashboard', [
            'companies' => $companies,
            'companies_count' => $companies->count(),
            'active_auto_dismiss_rules' => $this->autoDismissRules->active(),
            'source_health' => $this->sourceHealth->summary(),
        ]);
    }
}
