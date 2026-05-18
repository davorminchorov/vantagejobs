<?php

declare(strict_types=1);

namespace App\Http\Controllers\Companies;

use App\Enums\EventType;
use App\Http\Requests\Companies\AddFundingRequest;
use App\Models\Company;
use App\Models\Event;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

final readonly class AddFundingController
{
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

        DB::transaction(function () use ($company, $stage, $amount, $raisedAt, $url, $notes): void {
            // Only overwrite Company-level scalars when the new round is at
            // least as recent as the stored one. Stale historical entries
            // shouldn't downgrade the company's "most recent" view.
            $shouldUpdate = $raisedAt !== null && (
                $company->funding_last_raised_at === null
                || $raisedAt->greaterThanOrEqualTo($company->funding_last_raised_at)
            );
            if ($shouldUpdate) {
                $company->fill([
                    'funding_stage' => $stage ?? $company->funding_stage,
                    'funding_last_raised_at' => $raisedAt,
                    'funding_last_amount_usd' => $amount ?? $company->funding_last_amount_usd,
                ])->save();
            }

            $title = $stage !== null
                ? "Funding round: {$stage}".($amount !== null ? ' — '.self::humanAmount($amount) : '')
                : ($amount !== null ? 'Funding round — '.self::humanAmount($amount) : 'Funding round');

            Event::query()->create([
                'company_id' => $company->id,
                'role_id' => null,
                'type' => EventType::FUNDING->value,
                'title' => $title,
                'body' => (string) json_encode(array_filter([
                    'stage' => $stage,
                    'amount_usd' => $amount,
                    'raised_at' => $raisedAt?->toIso8601String(),
                    'url' => $url,
                    'notes' => $notes,
                    'source' => 'manual',
                ], static fn ($v) => $v !== null && $v !== '')),
                'occurred_at' => $raisedAt ?? CarbonImmutable::now(),
            ]);
        });

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

    private static function humanAmount(int $usd): string
    {
        if ($usd >= 1_000_000_000) {
            return '$'.number_format($usd / 1_000_000_000, 1).'B';
        }
        if ($usd >= 1_000_000) {
            return '$'.number_format($usd / 1_000_000, 1).'M';
        }
        if ($usd >= 1_000) {
            return '$'.number_format($usd / 1_000).'k';
        }

        return '$'.number_format($usd);
    }
}
