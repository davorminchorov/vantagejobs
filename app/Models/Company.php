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
        ];
    }

    /**
     * Companies eligible for an automated ATS scrape: ats_type + ats_slug are
     * both set, the company hasn't been graveyarded, and redetection hasn't
     * already given up on it. `needs_redetection` companies stay in scope —
     * the 404 counter and the redetect sweep both depend on scrapes still
     * running against them.
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
            ->where('ats_status', '!=', 'redetect_failed');
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
