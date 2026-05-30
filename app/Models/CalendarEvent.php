<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CalendarEventType;
use App\Tenancy\BelongsToTenant;
use Database\Factories\CalendarEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable([
    'owner_type',
    'owner_id',
    'title',
    'starts_at',
    'timezone',
    'type',
    'notes',
    'url',
    'reminder_minutes',
    'reminder_sent_at',
])]
final class CalendarEvent extends Model
{
    /** @use HasFactory<CalendarEventFactory> */
    use BelongsToTenant;

    use HasFactory;

    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'type' => CalendarEventType::class,
            'reminder_minutes' => 'integer',
            'reminder_sent_at' => 'immutable_datetime',
        ];
    }

    /**
     * Nullable polymorphic owner (role/company/contact). Resolved manually in
     * handlers per the no-route-model-binding convention; this relation is for
     * eager-loading convenience only.
     *
     * @return MorphTo<Model, $this>
     */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }
}
