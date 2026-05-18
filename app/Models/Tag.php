<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Database\Factories\TagFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

#[Fillable(['slug', 'name'])]
final class Tag extends Model
{
    /** @use HasFactory<TagFactory> */
    use BelongsToTenant;

    use HasFactory;

    public static function normalizeSlug(string $value): string
    {
        $trimmed = mb_strtolower(trim($value));

        return preg_replace('/\s+/', '-', $trimmed) ?? $trimmed;
    }

    /**
     * @return MorphToMany<Company, $this>
     */
    public function companies(): MorphToMany
    {
        return $this->morphedByMany(Company::class, 'taggable');
    }

    /**
     * @return MorphToMany<Role, $this>
     */
    public function roles(): MorphToMany
    {
        return $this->morphedByMany(Role::class, 'taggable');
    }
}
