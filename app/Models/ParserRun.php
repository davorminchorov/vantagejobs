<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Database\Factories\ParserRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per parser:reparse-all invocation.
 *
 * The command writes the row at start, updates `processed` after every chunk,
 * and sets `to_id` + `finished_at` on completion. If the run is killed
 * mid-stream, the row stays open and the operator can resume from the last
 * watermark using parser:reparse-all --from=ID.
 */
#[Fillable([
    'tenant_id',
    'target_version',
    'from_id',
    'to_id',
    'processed',
    'force_corrections',
    'source_filter',
    'started_at',
    'finished_at',
])]
final class ParserRun extends Model
{
    /** @use HasFactory<ParserRunFactory> */
    use BelongsToTenant;

    use HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'target_version' => 'integer',
            'from_id' => 'integer',
            'to_id' => 'integer',
            'processed' => 'integer',
            'force_corrections' => 'boolean',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }
}
