<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Database\Factories\FundingCandidateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'company_id',
    'source',
    'external_id',
    'title',
    'url',
    'published_at',
    'matched_keywords',
    'status',
    'fetched_at',
])]
final class FundingCandidate extends Model
{
    /** @use HasFactory<FundingCandidateFactory> */
    use BelongsToTenant;

    use HasFactory;

    public const STATUS_NEW = 'new';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_DISMISSED = 'dismissed';

    /**
     * Preset funding keywords scanned against article titles. Both the
     * GoogleNewsClient (for matching) and the persisted model reference
     * this list so the keyword set has one source of truth.
     *
     * @var list<string>
     */
    public const PRESET_KEYWORDS = [
        'raised',
        'Series A',
        'Series B',
        'Series C',
        'Series D',
        'Series E',
        'funding round',
        'closes round',
    ];

    protected function casts(): array
    {
        return [
            'matched_keywords' => 'array',
            'published_at' => 'immutable_datetime',
            'fetched_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
