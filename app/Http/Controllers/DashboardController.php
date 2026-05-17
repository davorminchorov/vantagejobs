<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Company;
use Inertia\Inertia;
use Inertia\Response;

final readonly class DashboardController
{
    public function __invoke(): Response
    {
        $companies = Company::query()
            ->orderBy('tier')
            ->orderBy('name')
            ->get(['id', 'name', 'website', 'ats_type', 'tier', 'status', 'last_checked_at']);

        return Inertia::render('Dashboard', [
            'companies' => $companies,
            'companies_count' => $companies->count(),
        ]);
    }
}
