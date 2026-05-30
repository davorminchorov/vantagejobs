<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TemplateCategory;
use App\Tenancy\BelongsToTenant;
use Database\Factories\TemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'category', 'body'])]
final class Template extends Model
{
    /** @use HasFactory<TemplateFactory> */
    use BelongsToTenant;

    use HasFactory;

    protected function casts(): array
    {
        return [
            'category' => TemplateCategory::class,
        ];
    }
}
