<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AuditLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AuditLog deliberately does NOT use BelongsToTenant.
 *
 * Auth failures fire before TenantContext can be resolved (no user, no
 * tenant). Forcing tenant context on inserts would prevent us from
 * logging failed-login events. Audit log queries that need tenant
 * scoping must filter explicitly.
 */
#[Fillable(['tenant_id', 'actor_id', 'action', 'outcome', 'ip', 'user_agent', 'context'])]
final class AuditLog extends Model
{
    /** @use HasFactory<AuditLogFactory> */
    use HasFactory;

    /**
     * Append-only — no updated_at column on the table.
     */
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
