<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Database\Factories\CompanySnapshotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'company_id',
    'snapshot_date',
    'open_role_count',
    'listings_count',
    'tier',
    'status',
    'github_score',
    'blog_posts_90d',
    'has_recent_layoff_event',
    'quality_score',
    'breakdown',
])]
final class CompanySnapshot extends Model
{
    /** @use HasFactory<CompanySnapshotFactory> */
    use BelongsToTenant;

    use HasFactory;

    protected function casts(): array
    {
        return [
            'snapshot_date' => 'immutable_date',
            'open_role_count' => 'integer',
            'listings_count' => 'integer',
            'github_score' => 'integer',
            'blog_posts_90d' => 'integer',
            'has_recent_layoff_event' => 'boolean',
            'quality_score' => 'integer',
            'breakdown' => 'array',
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
