<?php

declare(strict_types=1);

namespace App\Http\Controllers\Insights;

use App\Models\Company;
use App\Models\FundingCandidate;
use Illuminate\Http\RedirectResponse;

/**
 * Dismiss every candidate still waiting for review on one company. Several
 * articles usually cover the same round, so they are cleared together.
 */
final readonly class DismissCompanyFundingCandidatesController
{
    public function __invoke(int $company): RedirectResponse
    {
        /** @var Company $row */
        $row = Company::query()->findOrFail($company);

        $count = FundingCandidate::query()
            ->where('company_id', $row->id)
            ->where('status', FundingCandidate::STATUS_NEW)
            ->update(['status' => FundingCandidate::STATUS_DISMISSED]);

        return back()->with('success', "Dismissed {$count} ".($count === 1 ? 'headline' : 'headlines')." for {$row->name}.");
    }
}
