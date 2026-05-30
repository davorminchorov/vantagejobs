<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OutreachChannel;
use App\Tenancy\BelongsToTenant;
use Database\Factories\OutreachLogEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['contact_id', 'channel', 'subject', 'body', 'occurred_at'])]
final class OutreachLogEntry extends Model
{
    /** @use HasFactory<OutreachLogEntryFactory> */
    use BelongsToTenant;

    use HasFactory;

    /**
     * Append-only — no updated_at column on the table.
     */
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'channel' => OutreachChannel::class,
            'occurred_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
