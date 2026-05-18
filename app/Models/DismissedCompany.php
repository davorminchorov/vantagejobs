<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Database\Factories\DismissedCompanyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'normalized_company_key',
    'display_name',
    'dismiss_count',
    'auto_dismiss',
    'first_dismissed_at',
    'last_dismissed_at',
])]
final class DismissedCompany extends Model
{
    /** @use HasFactory<DismissedCompanyFactory> */
    use BelongsToTenant;

    use HasFactory;

    protected function casts(): array
    {
        return [
            'dismiss_count' => 'integer',
            'auto_dismiss' => 'boolean',
            'first_dismissed_at' => 'immutable_datetime',
            'last_dismissed_at' => 'immutable_datetime',
        ];
    }
}
