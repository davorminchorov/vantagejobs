<?php

declare(strict_types=1);

namespace App\Http\Controllers\Insights;

use App\Models\FundingAnnouncement;
use Illuminate\Http\RedirectResponse;

/**
 * Hide a company from Recently funded: dismisses every headline about it
 * (same company key), or just this headline when no company was found.
 */
final readonly class DismissFundingAnnouncementController
{
    public function __invoke(int $announcement): RedirectResponse
    {
        /** @var FundingAnnouncement $row */
        $row = FundingAnnouncement::query()->findOrFail($announcement);

        FundingAnnouncement::query()
            ->where('status', FundingAnnouncement::STATUS_NEW)
            ->when(
                $row->company_key !== null,
                fn ($query) => $query->where('company_key', $row->company_key),
                fn ($query) => $query->whereKey($row->id),
            )
            ->update(['status' => FundingAnnouncement::STATUS_DISMISSED]);

        return back()->with('success', 'Dismissed '.($row->company_name ?? 'the headline').'.');
    }
}
