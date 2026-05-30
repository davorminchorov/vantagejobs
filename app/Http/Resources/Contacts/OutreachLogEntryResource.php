<?php

declare(strict_types=1);

namespace App\Http\Resources\Contacts;

use App\Models\OutreachLogEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Wire shape for a single append-only outreach log entry.
 *
 * @mixin OutreachLogEntry
 */
final class OutreachLogEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'channel' => $this->channel->value,
            'subject' => $this->subject,
            'body' => $this->body,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
        ];
    }
}
