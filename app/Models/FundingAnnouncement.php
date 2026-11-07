<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Database\Factories\FundingAnnouncementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A funding headline from a startup news feed, about any company. Listed on
 * Insights > Recently funded so new companies can be discovered and tracked.
 */
#[Fillable([
    'source',
    'external_id',
    'title',
    'url',
    'summary',
    'categories',
    'industries',
    'is_software',
    'published_at',
    'company_name',
    'company_key',
    'stage',
    'amount_usd',
    'amount_label',
    'status',
    'company_id',
])]
final class FundingAnnouncement extends Model
{
    /** @use HasFactory<FundingAnnouncementFactory> */
    use BelongsToTenant;

    use HasFactory;

    public const STATUS_NEW = 'new';

    public const STATUS_DISMISSED = 'dismissed';

    public const STATUS_TRACKED = 'tracked';

    /** Feed items published longer ago than this are not stored or listed. */
    public const MAX_AGE_DAYS = 90;

    protected function casts(): array
    {
        return [
            'published_at' => 'immutable_datetime',
            'amount_usd' => 'integer',
            'categories' => 'array',
            'industries' => 'array',
            'is_software' => 'boolean',
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
