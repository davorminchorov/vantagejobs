<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pipeline\Offers;

use App\Enums\EventType;
use App\Http\Requests\Pipeline\Offers\UpsertOfferRequest;
use App\Models\Event;
use App\Models\Offer;
use App\Models\OfferRevision;
use App\Models\Role;
use App\Pipeline\Offers\TotalCompCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\RedirectResponse;

final readonly class UpsertController
{
    public function __construct(
        private ConnectionInterface $db,
        private TotalCompCalculator $calculator,
    ) {}

    public function __invoke(UpsertOfferRequest $request, int $role): RedirectResponse
    {
        /** @var Role $roleModel */
        $roleModel = Role::query()->findOrFail($role);

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $attributes = [
            'currency' => strtoupper((string) $data['currency']),
            'base' => $this->asInt($data['base'] ?? null),
            'bonus' => $this->asInt($data['bonus'] ?? null),
            'signing_bonus' => $this->asInt($data['signing_bonus'] ?? null),
            'equity_shares' => $this->asInt($data['equity_shares'] ?? null),
            'equity_strike' => $this->asFloat($data['equity_strike'] ?? null),
            'equity_assumed_price' => $this->asFloat($data['equity_assumed_price'] ?? null),
            'vesting_years' => $this->asInt($data['vesting_years'] ?? null),
            'decision_deadline' => isset($data['decision_deadline']) && $data['decision_deadline'] !== null
                ? CarbonImmutable::parse((string) $data['decision_deadline'])
                : null,
            'notes' => $this->asString($data['notes'] ?? null),
        ];

        $this->db->transaction(function () use ($roleModel, $attributes): void {
            /** @var Offer $offer */
            $offer = Offer::query()->updateOrCreate(
                ['role_id' => $roleModel->id],
                $attributes,
            );

            $totalComp = $this->calculator->forOffer($offer);
            $offer->forceFill(['computed_total_comp_usd' => $totalComp])->save();

            OfferRevision::query()->create([
                'offer_id' => $offer->id,
                'snapshot' => [
                    'currency' => $offer->currency,
                    'base' => $offer->base !== null ? (int) $offer->base : null,
                    'bonus' => $offer->bonus !== null ? (int) $offer->bonus : null,
                    'signing_bonus' => $offer->signing_bonus !== null ? (int) $offer->signing_bonus : null,
                    'equity_shares' => $offer->equity_shares !== null ? (int) $offer->equity_shares : null,
                    'equity_strike' => $offer->equity_strike !== null ? (float) $offer->equity_strike : null,
                    'equity_assumed_price' => $offer->equity_assumed_price !== null ? (float) $offer->equity_assumed_price : null,
                    'vesting_years' => $offer->vesting_years !== null ? (int) $offer->vesting_years : null,
                    'decision_deadline' => $offer->decision_deadline?->toDateString(),
                    'notes' => $offer->notes,
                ],
                'computed_total_comp_usd' => $totalComp,
            ]);

            Event::query()->create([
                'company_id' => $roleModel->company_id,
                'role_id' => $roleModel->id,
                'type' => EventType::OFFER_RECORDED->value,
                'title' => 'Offer recorded: '.$this->humanAmount($totalComp).' total comp (USD)',
                'body' => (string) json_encode([
                    'currency' => $offer->currency,
                    'computed_total_comp_usd' => $totalComp,
                    'base' => $offer->base !== null ? (int) $offer->base : null,
                    'decision_deadline' => $offer->decision_deadline?->toDateString(),
                    'source' => 'manual',
                ]),
                'occurred_at' => CarbonImmutable::now(),
            ]);
        });

        return redirect()
            ->back()
            ->with('success', "Recorded offer for {$roleModel->title}.");
    }

    private function asInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function asFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }

    private function asString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function humanAmount(int $usd): string
    {
        if ($usd >= 1_000_000) {
            return '$'.number_format($usd / 1_000_000, 1).'M';
        }
        if ($usd >= 1_000) {
            return '$'.number_format($usd / 1_000).'k';
        }

        return '$'.number_format($usd);
    }
}
