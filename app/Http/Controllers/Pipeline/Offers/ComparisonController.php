<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pipeline\Offers;

use App\Enums\PipelineStatus;
use App\Models\Offer;
use Illuminate\Http\JsonResponse;

final readonly class ComparisonController
{
    /**
     * List every offer attached to a role currently sitting at the OFFER
     * pipeline stage for the active tenant, ordered by computed USD total
     * comp (highest first) so the comparison view can rank them. Tenant
     * scoping is enforced by the BelongsToTenant global scope on every model.
     */
    public function __invoke(): JsonResponse
    {
        $offers = Offer::query()
            ->with(['role:id,title,company_id,pipeline_status', 'role.company:id,name'])
            ->whereHas('role', static function ($query): void {
                $query->where('pipeline_status', PipelineStatus::OFFER->value);
            })
            ->orderByDesc('computed_total_comp_usd')
            ->get();

        $rows = $offers->map(static function (Offer $offer): array {
            $role = $offer->role;

            return [
                'offer_id' => $offer->id,
                'role_id' => $offer->role_id,
                'role_title' => $role?->title,
                'company_name' => $role?->company?->name,
                'currency' => $offer->currency,
                'base' => $offer->base !== null ? (int) $offer->base : null,
                'bonus' => $offer->bonus !== null ? (int) $offer->bonus : null,
                'signing_bonus' => $offer->signing_bonus !== null ? (int) $offer->signing_bonus : null,
                'equity_shares' => $offer->equity_shares !== null ? (int) $offer->equity_shares : null,
                'vesting_years' => $offer->vesting_years !== null ? (int) $offer->vesting_years : null,
                'computed_total_comp_usd' => $offer->computed_total_comp_usd !== null
                    ? (int) $offer->computed_total_comp_usd
                    : null,
                'decision_deadline' => $offer->decision_deadline?->toDateString(),
            ];
        })->all();

        $best = $offers
            ->filter(static fn (Offer $offer): bool => $offer->computed_total_comp_usd !== null)
            ->sortByDesc('computed_total_comp_usd')
            ->first();

        return new JsonResponse([
            'offers' => $rows,
            'best_offer_id' => $best?->id,
            'count' => count($rows),
        ]);
    }
}
