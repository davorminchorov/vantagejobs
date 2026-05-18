<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AggregatorSourceType;
use App\Tenancy\BelongsToTenant;
use Database\Factories\AggregatorSourceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'type',
    'name',
    'enabled',
    'cadence_minutes',
    'last_run_at',
    'next_run_at',
    'last_error',
    'consecutive_failures',
])]
final class AggregatorSource extends Model
{
    /** @use HasFactory<AggregatorSourceFactory> */
    use BelongsToTenant;

    use HasFactory;

    protected function casts(): array
    {
        return [
            'type' => AggregatorSourceType::class,
            'enabled' => 'boolean',
            'cadence_minutes' => 'integer',
            'last_run_at' => 'immutable_datetime',
            'next_run_at' => 'immutable_datetime',
            'consecutive_failures' => 'integer',
        ];
    }

    /**
     * @return HasMany<AggregatorListing, $this>
     */
    public function listings(): HasMany
    {
        return $this->hasMany(AggregatorListing::class, 'source_id');
    }
}
