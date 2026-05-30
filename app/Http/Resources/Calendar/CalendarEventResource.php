<?php

declare(strict_types=1);

namespace App\Http\Resources\Calendar;

use App\Models\CalendarEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Wire shape for a single calendar event.
 *
 * starts_at is always emitted as a UTC ISO-8601 string (canonical storage);
 * the original IANA zone the user entered ships alongside in `timezone` so the
 * client can render the local wall-clock time and label.
 *
 * @mixin CalendarEvent
 */
final class CalendarEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'owner_type' => $this->owner_type,
            'owner_id' => $this->owner_id !== null ? (int) $this->owner_id : null,
            'title' => $this->title,
            'starts_at' => $this->starts_at?->utc()->toIso8601String(),
            'timezone' => $this->timezone,
            'type' => $this->type?->value,
            'notes' => $this->notes,
            'url' => $this->url,
            'reminder_minutes' => $this->reminder_minutes !== null ? (int) $this->reminder_minutes : null,
            'reminder_sent_at' => $this->reminder_sent_at?->utc()->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
