<?php

declare(strict_types=1);

namespace App\Http\Controllers\Insights;

use App\Enums\EventType;
use App\Models\Event;
use App\Models\FundingCandidate;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\RedirectResponse;

/**
 * Promote a funding candidate. Flips status to 'confirmed' and writes
 * a FUNDING Event on the underlying company. We deliberately do NOT
 * touch Company funding scalars — the user can fill those in manually
 * if they know stage/amount; the news headline alone isn't reliable
 * enough.
 */
final readonly class ConfirmFundingCandidateController
{
    public function __construct(private ConnectionInterface $db) {}

    public function __invoke(int $candidate): RedirectResponse
    {
        /** @var FundingCandidate $row */
        $row = FundingCandidate::query()->findOrFail($candidate);

        $this->db->transaction(function () use ($row): void {
            $row->fill(['status' => FundingCandidate::STATUS_CONFIRMED])->save();

            Event::query()->create([
                'company_id' => $row->company_id,
                'role_id' => null,
                'type' => EventType::FUNDING->value,
                'title' => $row->title,
                'body' => (string) json_encode([
                    'source' => 'google_news',
                    'candidate_id' => $row->id,
                    'url' => $row->url,
                    'matched_keywords' => $row->matched_keywords,
                    'published_at' => $row->published_at?->toIso8601String(),
                ], JSON_THROW_ON_ERROR),
                'occurred_at' => $row->published_at ?? CarbonImmutable::now(),
            ]);
        });

        return back()->with('success', 'Funding candidate confirmed.');
    }
}
