<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Database\Factories\BookmarkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable([
    'bookmarkable_type',
    'bookmarkable_id',
    'notes',
    'expires_at',
    'expires_warned_at',
])]
final class Bookmark extends Model
{
    /** @use HasFactory<BookmarkFactory> */
    use BelongsToTenant;

    use HasFactory;

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'expires_warned_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return MorphTo<\Illuminate\Database\Eloquent\Model, $this>
     */
    public function bookmarkable(): MorphTo
    {
        return $this->morphTo();
    }
}
