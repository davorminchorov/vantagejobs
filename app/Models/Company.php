<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

#[Fillable([
    'name',
    'website',
    'careers_url',
    'domain',
    'ats_type',
    'ats_slug',
    'ats_host',
    'tier',
    'status',
    'last_checked_at',
    'etag',
    'last_modified',
    'funding_stage',
    'funding_last_raised_at',
    'funding_last_amount_usd',
    'github_org',
    'eng_blog_rss_url',
    'is_active',
    'ats_status',
    'ats_consecutive_404s',
    'ats_redetect_attempts',
    'ats_consecutive_failures',
])]
final class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use BelongsToTenant;

    use HasFactory;

    protected function casts(): array
    {
        return [
            'last_checked_at' => 'immutable_datetime',
            'funding_last_raised_at' => 'immutable_datetime',
            'funding_last_amount_usd' => 'integer',
            'is_active' => 'boolean',
            'ats_consecutive_404s' => 'integer',
            'ats_redetect_attempts' => 'integer',
            'ats_consecutive_failures' => 'integer',
        ];
    }

    /**
     * Companies eligible for an automated ATS scrape: ats_type + ats_slug are
     * both set, the company hasn't been graveyarded, and its board isn't
     * flagged as dead. `needs_redetection` companies are paused: their board
     * has already answered 404/401/403/410 seven times in a row, so further
     * polls only repeat the failure while signals:ats:redetect looks for a
     * new board. A successful redetect flips them back to `ok`; three misses
     * lock them at `redetect_failed` for manual review.
     *
     * @param  Builder<Company>  $query
     * @return Builder<Company>
     */
    public function scopeScrapeable(Builder $query): Builder
    {
        return $query
            ->whereNotNull('ats_type')
            ->whereNotNull('ats_slug')
            ->where('is_active', true)
            ->whereNotIn('ats_status', ['needs_redetection', 'redetect_failed']);
    }

    /**
     * @return HasMany<Role, $this>
     */
    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }

    /**
     * @return HasMany<Event, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    /**
     * @return HasMany<Note, $this>
     */
    public function notes(): HasMany
    {
        return $this->hasMany(Note::class);
    }

    /**
     * @return MorphToMany<Tag, $this>
     */
    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }
}
