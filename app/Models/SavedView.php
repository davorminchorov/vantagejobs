<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Database\Factories\SavedViewFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'name',
    'entity',
    'filters',
    'sort_order',
    'is_default',
])]
final class SavedView extends Model
{
    /** @use HasFactory<SavedViewFactory> */
    use BelongsToTenant;

    use HasFactory;

    public const ENTITY_COMPANIES = 'companies';

    public const ENTITY_ROLES = 'roles';

    public const ENTITY_DISCOVER = 'discover';

    public const VALID_ENTITIES = [
        self::ENTITY_COMPANIES,
        self::ENTITY_ROLES,
        self::ENTITY_DISCOVER,
    ];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'is_default' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
