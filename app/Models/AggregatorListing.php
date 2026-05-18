<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AggregatorListingStatus;
use App\Tenancy\BelongsToTenant;
use Database\Factories\AggregatorListingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable([
    'source_id',
    'external_id',
    'url',
    'raw',
    'parsed',
    'parsed_company_name',
    'parsed_role_title',
    'parsed_seniority',
    'parsed_remote_type',
    'parsed_comp_min_usd',
    'parsed_comp_max_usd',
    'has_comp',
    'stack_tags',
    'normalized_company_key',
    'normalized_role_key',
    'location_bucket',
    'status',
    'matched_company_id',
    'duplicate_of_id',
    'posted_at',
    'fetched_at',
    'snoozed_until',
])]
final class AggregatorListing extends Model
{
    /** @use HasFactory<AggregatorListingFactory> */
    use BelongsToTenant;

    use HasFactory;

    protected function casts(): array
    {
        return [
            'raw' => 'array',
            'parsed' => 'array',
            'stack_tags' => 'array',
            'has_comp' => 'boolean',
            'status' => AggregatorListingStatus::class,
            'parsed_comp_min_usd' => 'integer',
            'parsed_comp_max_usd' => 'integer',
            'posted_at' => 'immutable_datetime',
            'fetched_at' => 'immutable_datetime',
            'snoozed_until' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<AggregatorSource, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(AggregatorSource::class, 'source_id');
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function matchedCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'matched_company_id');
    }

    /**
     * @return BelongsTo<AggregatorListing, $this>
     */
    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(AggregatorListing::class, 'duplicate_of_id');
    }

    /**
     * @return MorphMany<Bookmark, $this>
     */
    public function bookmarks(): MorphMany
    {
        return $this->morphMany(Bookmark::class, 'bookmarkable');
    }
}
