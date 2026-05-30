<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ExportJobStatus;
use App\Tenancy\BelongsToTenant;
use Database\Factories\ExportJobFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'tenant_id',
    'user_id',
    'status',
    'formats',
    'manifest',
    'disk',
    'path',
    'schema_version',
    'error',
    'expires_at',
])]
final class ExportJob extends Model
{
    /** @use HasFactory<ExportJobFactory> */
    use BelongsToTenant;

    use HasFactory;

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'status' => ExportJobStatus::class,
            'formats' => 'array',
            'manifest' => 'array',
            'expires_at' => 'immutable_datetime',
        ];
    }
}
