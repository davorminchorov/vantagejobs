<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Database\Factories\CompanyGraveyardReasonFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'company_id',
    'demoted_by_user_id',
    'restored_by_user_id',
    'demoted_at',
    'restored_at',
    'reason',
    'tags',
])]
final class CompanyGraveyardReason extends Model
{
    /** @use HasFactory<CompanyGraveyardReasonFactory> */
    use BelongsToTenant;

    use HasFactory;

    /**
     * Preset tag whitelist per PRD §6.2.8. Free-form tags are accepted at
     * the service layer but the UI surfaces these as one-click chips.
     */
    public const array PRESET_TAGS = [
        'wrong_stack',
        'bad_culture_signal',
        'comp_too_low',
        'geo_restrictive',
        'layoffs',
        'not_hiring_anymore',
        'lost_interest',
    ];

    protected function casts(): array
    {
        return [
            'demoted_at' => 'immutable_datetime',
            'restored_at' => 'immutable_datetime',
            'tags' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
