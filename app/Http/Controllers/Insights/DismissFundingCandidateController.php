<?php

declare(strict_types=1);

namespace App\Http\Controllers\Insights;

use App\Models\FundingCandidate;
use Illuminate\Http\RedirectResponse;

final readonly class DismissFundingCandidateController
{
    public function __invoke(int $candidate): RedirectResponse
    {
        /** @var FundingCandidate $row */
        $row = FundingCandidate::query()->findOrFail($candidate);
        $row->fill(['status' => FundingCandidate::STATUS_DISMISSED])->save();

        return back()->with('success', 'Funding candidate dismissed.');
    }
}
