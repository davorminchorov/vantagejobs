<?php

declare(strict_types=1);

namespace App\Http\Controllers\Companies;

use App\Http\Requests\Companies\AddFundingRequest;
use App\Models\Company;
use App\Signals\Funding\FundingRoundRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;

final readonly class AddFundingController
{
    public function __construct(private FundingRoundRecorder $recorder) {}

    public function __invoke(AddFundingRequest $request, Company $company): RedirectResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $stage = $this->asString($data['stage'] ?? null);
        $amount = isset($data['amount_usd']) && $data['amount_usd'] !== null
            ? (int) $data['amount_usd']
            : null;
        $raisedAt = isset($data['raised_at']) && $data['raised_at'] !== null
            ? CarbonImmutable::parse((string) $data['raised_at'])
            : null;
        $url = $this->asString($data['url'] ?? null);
        $notes = $this->asString($data['notes'] ?? null);

        $this->recorder->record(
            company: $company,
            stage: $stage,
            amountUsd: $amount,
            raisedAt: $raisedAt,
            url: $url,
            notes: $notes,
            context: ['source' => 'manual'],
        );

        return redirect()
            ->route('companies.show', $company)
            ->with('success', "Recorded funding event for {$company->name}.");
    }

    private function asString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
