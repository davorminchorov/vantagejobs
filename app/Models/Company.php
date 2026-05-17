<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name',
    'website',
    'careers_url',
    'domain',
    'ats_type',
    'ats_slug',
    'tier',
    'status',
    'last_checked_at',
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
        ];
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
}
