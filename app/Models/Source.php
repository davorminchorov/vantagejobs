<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Database\Factories\SourceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'type', 'enabled', 'last_run_at', 'last_error'])]
final class Source extends Model
{
    /** @use HasFactory<SourceFactory> */
    use BelongsToTenant;

    use HasFactory;

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'last_run_at' => 'immutable_datetime',
        ];
    }
}
