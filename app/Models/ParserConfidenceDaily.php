<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Database\Factories\ParserConfidenceDailyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'source_id',
    'field',
    'day',
    'n',
    'n_missing',
    'p10',
    'p50',
    'p90',
    'n_corrections',
])]
final class ParserConfidenceDaily extends Model
{
    /** @use HasFactory<ParserConfidenceDailyFactory> */
    use BelongsToTenant;

    use HasFactory;

    protected $table = 'parser_confidence_daily';

    protected function casts(): array
    {
        return [
            'day' => 'immutable_date',
            'n' => 'integer',
            'n_missing' => 'integer',
            'p10' => 'float',
            'p50' => 'float',
            'p90' => 'float',
            'n_corrections' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<AggregatorSource, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(AggregatorSource::class, 'source_id');
    }
}
