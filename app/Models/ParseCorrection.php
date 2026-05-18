<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Database\Factories\ParseCorrectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'listing_id',
    'user_id',
    'field',
    'old_value',
    'old_confidence',
    'old_source',
    'new_value',
])]
final class ParseCorrection extends Model
{
    /** @use HasFactory<ParseCorrectionFactory> */
    use BelongsToTenant;

    use HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'old_value' => 'array',
            'new_value' => 'array',
            'old_confidence' => 'float',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<AggregatorListing, $this>
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(AggregatorListing::class, 'listing_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
