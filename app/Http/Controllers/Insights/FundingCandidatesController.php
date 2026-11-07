<?php

declare(strict_types=1);

namespace App\Http\Controllers\Insights;

use App\Models\Company;
use App\Models\FundingCandidate;
use App\Signals\Funding\FundingHeadlineParser;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Review queue for funding headlines found by signals:funding:news,
 * grouped by company. Several articles usually cover one round, so each
 * company shows its headlines newest first next to the funding already on
 * file. Confirming records the round; dismissing drops the headline.
 */
final readonly class FundingCandidatesController
{
    public function __construct(private FundingHeadlineParser $parser) {}

    public function __invoke(): Response
    {
        /** @var Collection<int, FundingCandidate> $rows */
        $rows = FundingCandidate::query()
            ->with('company:id,name,tier,funding_stage,funding_last_raised_at,funding_last_amount_usd')
            ->where('status', FundingCandidate::STATUS_NEW)
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->get();

        $groups = $rows
            ->groupBy('company_id')
            ->map(function (Collection $candidates): array {
                /** @var FundingCandidate $first */
                $first = $candidates->first();
                /** @var Company|null $company */
                $company = $first->company;

                return [
                    'company' => [
                        'id' => $first->company_id,
                        'name' => $company?->name ?? '(unknown)',
                        'tier' => $company?->tier,
                        'funding' => [
                            'stage' => $company?->funding_stage,
                            'amount_usd' => $company?->funding_last_amount_usd,
                            'raised_at' => $company?->funding_last_raised_at?->toDateString(),
                        ],
                    ],
                    'latest_published_at' => $first->published_at?->toIso8601String(),
                    'candidates' => $candidates->map(fn (FundingCandidate $row): array => $this->present($row))->values()->all(),
                ];
            })
            ->sortByDesc('latest_published_at')
            ->values();

        return Inertia::render('Insights/FundingCandidates', [
            'groups' => $groups,
            'summary' => [
                'headlines' => $rows->count(),
                'companies' => $groups->count(),
                'max_age_months' => FundingCandidate::MAX_AGE_MONTHS,
            ],
        ]);
    }

    /**
     * @return array{id: int, headline: string, publisher: string|null, url: string, published_at: string|null, stage: string|null, amount_usd: int|null, amount_label: string|null}
     */
    private function present(FundingCandidate $row): array
    {
        $parsed = $this->parser->parse((string) $row->title);

        return [
            'id' => $row->id,
            'headline' => $parsed['headline'],
            'publisher' => $parsed['publisher'],
            'url' => (string) $row->url,
            'published_at' => $row->published_at?->toIso8601String(),
            'stage' => $parsed['stage'],
            'amount_usd' => $parsed['amount_usd'],
            'amount_label' => $parsed['amount_label'],
        ];
    }
}
