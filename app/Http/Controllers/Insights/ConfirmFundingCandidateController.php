<?php

declare(strict_types=1);

namespace App\Http\Controllers\Insights;

use App\Http\Requests\Insights\ConfirmFundingCandidateRequest;
use App\Models\Company;
use App\Models\FundingCandidate;
use App\Signals\Funding\FundingHeadlineParser;
use App\Signals\Funding\FundingRoundRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\RedirectResponse;

/**
 * Promote a funding candidate. Records the round on the company with the
 * details the reviewer confirmed (stage, amount, date), which also updates
 * the company's funding fields when the round is the most recent one, and
 * marks the candidate confirmed.
 */
final readonly class ConfirmFundingCandidateController
{
    public function __construct(
        private ConnectionInterface $db,
        private FundingRoundRecorder $recorder,
        private FundingHeadlineParser $parser,
    ) {}

    public function __invoke(ConfirmFundingCandidateRequest $request, int $candidate): RedirectResponse
    {
        /** @var FundingCandidate $row */
        $row = FundingCandidate::query()->findOrFail($candidate);
        /** @var Company $company */
        $company = Company::query()->findOrFail($row->company_id);

        /** @var array<string, mixed> $data */
        $data = $request->validated();
        $stage = self::text($data['stage'] ?? null);
        $amount = isset($data['amount_usd']) ? (int) $data['amount_usd'] : null;
        $raisedAt = isset($data['raised_at'])
            ? CarbonImmutable::parse((string) $data['raised_at'])
            : $row->published_at;

        $this->db->transaction(function () use ($row, $company, $stage, $amount, $raisedAt, $data): void {
            $this->recorder->record(
                company: $company,
                stage: $stage,
                amountUsd: $amount,
                raisedAt: $raisedAt,
                url: $row->url,
                notes: self::text($data['notes'] ?? null),
                context: [
                    'source' => 'google_news',
                    'candidate_id' => $row->id,
                    'matched_keywords' => $row->matched_keywords,
                    'published_at' => $row->published_at?->toIso8601String(),
                ],
                title: $this->parser->parse($row->title)['headline'],
            );

            $row->fill(['status' => FundingCandidate::STATUS_CONFIRMED])->save();
        });

        return back()->with('success', "Recorded funding for {$company->name}.");
    }

    private static function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
