<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ApplicationSource;
use App\Enums\PipelineStatus;
use App\Enums\WorkplaceType;
use App\Tenancy\BelongsToTenant;
use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

#[Fillable([
    'company_id',
    'source',
    'external_id',
    'title',
    'url',
    'location',
    'is_remote',
    'is_open',
    'workplace_type',
    'department',
    'team',
    'comp_min',
    'comp_max',
    'comp_currency',
    'comp_interval',
    'raw',
    'posted_at',
    'last_seen_at',
    'pipeline_status',
    'application_source',
    'applied_at',
    'referral_contact_id',
    'next_action',
    'next_action_date',
    'general_notes',
    'interview_prep',
])]
final class Role extends Model
{
    /** @use HasFactory<RoleFactory> */
    use BelongsToTenant;

    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_remote' => 'boolean',
            'is_open' => 'boolean',
            'workplace_type' => WorkplaceType::class,
            'comp_min' => 'integer',
            'comp_max' => 'integer',
            'raw' => 'array',
            'posted_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'pipeline_status' => PipelineStatus::class,
            'application_source' => ApplicationSource::class,
            'applied_at' => 'immutable_datetime',
            'referral_contact_id' => 'integer',
            'next_action_date' => 'immutable_date',
        ];
    }

    /**
     * @return MorphToMany<Tag, $this>
     */
    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return HasMany<RoleStageNote, $this>
     */
    public function stageNotes(): HasMany
    {
        return $this->hasMany(RoleStageNote::class);
    }

    /**
     * @return HasOne<Offer, $this>
     */
    public function offer(): HasOne
    {
        return $this->hasOne(Offer::class);
    }
}
