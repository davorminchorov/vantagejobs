<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WorkplaceType;
use App\Tenancy\BelongsToTenant;
use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
}
