<?php

declare(strict_types=1);

namespace App\Http\Controllers\Insights;

use App\Models\Company;
use App\Models\FundingCandidate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Lists candidate funding events surfaced by signals:funding:news.
 * Operators promote (confirm) or drop (dismiss) each row from the
 * review queue. Promotion writes a manual funding Event; Company
 * scalars are never touched automatically — humans stay in the loop.
 */
final readonly class FundingCandidatesController
{
    public function __invoke(): Response
    {
        $rows = FundingCandidate::query()
            ->where('status', FundingCandidate::STATUS_NEW)
            ->orderByDesc('fetched_at')
            ->get();

        $companyIds = $rows->pluck('company_id')->unique()->all();
        $companies = Company::query()
            ->whereIn('id', $companyIds)
            ->get(['id', 'name'])
            ->keyBy('id');

        $candidates = $rows->map(fn (FundingCandidate $row): array => [
            'id' => $row->id,
            'company' => [
                'id' => $row->company_id,
                'name' => $companies->get($row->company_id)?->name ?? '(unknown)',
            ],
            'title' => $row->title,
            'url' => $row->url,
            'matched_keywords' => $row->matched_keywords,
            'published_at' => $row->published_at?->toIso8601String(),
            'fetched_at' => $row->fetched_at?->toIso8601String(),
        ])->values();

        return Inertia::render('Insights/FundingCandidates', [
            'candidates' => $candidates,
        ]);
    }
}
