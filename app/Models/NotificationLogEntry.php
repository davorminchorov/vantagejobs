<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NotificationKind;
use App\Tenancy\BelongsToTenant;
use Database\Factories\NotificationLogEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['tenant_id', 'kind', 'company_id', 'recipient', 'subject', 'context', 'sent_at'])]
final class NotificationLogEntry extends Model
{
    /** @use HasFactory<NotificationLogEntryFactory> */
    use BelongsToTenant;

    use HasFactory;

    protected $table = 'notification_log';

    /**
     * Append-only — no updated_at column on the table.
     */
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'kind' => NotificationKind::class,
            'company_id' => 'integer',
            'context' => 'array',
            'sent_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
