<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PipelineStatus;
use App\Tenancy\BelongsToTenant;
use Database\Factories\RoleStageNoteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['role_id', 'stage', 'body'])]
final class RoleStageNote extends Model
{
    /** @use HasFactory<RoleStageNoteFactory> */
    use BelongsToTenant;

    use HasFactory;

    /**
     * Append-only — no updated_at column on the table.
     */
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'stage' => PipelineStatus::class,
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
