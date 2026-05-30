<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Database\Factories\OfferFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'role_id',
    'currency',
    'base',
    'bonus',
    'signing_bonus',
    'equity_shares',
    'equity_strike',
    'equity_assumed_price',
    'vesting_years',
    'computed_total_comp_usd',
    'decision_deadline',
    'notes',
])]
final class Offer extends Model
{
    /** @use HasFactory<OfferFactory> */
    use BelongsToTenant;

    use HasFactory;

    protected function casts(): array
    {
        return [
            'base' => 'integer',
            'bonus' => 'integer',
            'signing_bonus' => 'integer',
            'equity_shares' => 'integer',
            'equity_strike' => 'decimal:4',
            'equity_assumed_price' => 'decimal:4',
            'vesting_years' => 'integer',
            'computed_total_comp_usd' => 'integer',
            'decision_deadline' => 'immutable_date',
        ];
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * @return HasMany<OfferRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(OfferRevision::class);
    }
}
