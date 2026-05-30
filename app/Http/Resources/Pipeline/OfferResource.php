<?php

declare(strict_types=1);

namespace App\Http\Resources\Pipeline;

use App\Models\Offer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Offer
 */
final class OfferResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'role_id' => $this->role_id,
            'currency' => $this->currency,
            'base' => $this->base !== null ? (int) $this->base : null,
            'bonus' => $this->bonus !== null ? (int) $this->bonus : null,
            'signing_bonus' => $this->signing_bonus !== null ? (int) $this->signing_bonus : null,
            'equity_shares' => $this->equity_shares !== null ? (int) $this->equity_shares : null,
            'equity_strike' => $this->equity_strike !== null ? (float) $this->equity_strike : null,
            'equity_assumed_price' => $this->equity_assumed_price !== null ? (float) $this->equity_assumed_price : null,
            'vesting_years' => $this->vesting_years !== null ? (int) $this->vesting_years : null,
            'computed_total_comp_usd' => $this->computed_total_comp_usd !== null ? (int) $this->computed_total_comp_usd : null,
            'decision_deadline' => $this->decision_deadline?->toDateString(),
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'revisions' => $this->whenLoaded('revisions', fn (): array => $this->revisions
                ->map(static fn ($revision): array => [
                    'id' => $revision->id,
                    'snapshot' => $revision->snapshot,
                    'computed_total_comp_usd' => $revision->computed_total_comp_usd !== null
                        ? (int) $revision->computed_total_comp_usd
                        : null,
                    'created_at' => $revision->created_at?->toIso8601String(),
                ])
                ->all()),
        ];
    }
}
