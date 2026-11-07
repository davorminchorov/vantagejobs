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

    /**
     * Articles published longer ago than this are not stored, so the review
     * queue only holds recent news.
     */
    public const MAX_AGE_MONTHS = 12;

    /**
     * Company directory and profile sites. Their pages ("2026 Funding Rounds
     * & List of Investors") match funding keywords without reporting news.
     * Compared against the RSS source host and the publisher name with
     * spaces and punctuation removed.
     *
     * @var list<string>
     */
    public const DIRECTORY_SITES = [
        'tracxn',
        'getlatka',
        'crunchbase',
        'pitchbook',
        'cbinsights',
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
