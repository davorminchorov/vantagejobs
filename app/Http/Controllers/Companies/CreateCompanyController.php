<?php

declare(strict_types=1);

namespace App\Http\Controllers\Companies;

use App\Enums\AtsType;
use App\Models\FundingAnnouncement;
use App\Signals\Funding\FundingFeedSource;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final readonly class CreateCompanyController
{
    public function __invoke(Request $request): Response
    {
        $announcementId = filter_var($request->query('funding_announcement'), FILTER_VALIDATE_INT);
        $announcement = $announcementId !== false
            ? FundingAnnouncement::query()->find($announcementId)
            : null;

        return Inertia::render('Companies/Create', [
            'options' => $this->options(),
            'initial' => $announcement instanceof FundingAnnouncement
                ? ['name' => $announcement->company_name ?? '', 'funding_announcement_id' => $announcement->id]
                : (object) [],
            'from_announcement' => $announcement instanceof FundingAnnouncement ? [
                'title' => $announcement->title,
                'url' => $announcement->url,
                'source' => FundingFeedSource::tryFrom($announcement->source)?->label() ?? $announcement->source,
            ] : null,
        ]);
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function options(): array
    {
        return [
            'tiers' => ['A', 'B', 'C', 'graveyard'],
            'statuses' => ['researching', 'watching', 'applied', 'in_process', 'rejected', 'offer', 'passed'],
            'ats_types' => array_map(fn (AtsType $t) => $t->value, AtsType::cases()),
        ];
    }
}
