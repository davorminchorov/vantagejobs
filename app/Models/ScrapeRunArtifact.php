<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ScrapeRunArtifactFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per raw HTTP response captured by RecordResponseArtifact.
 *
 * The Saloon middleware writes the body to the local filesystem (gzipped) and
 * stores the path + metadata here. Tenant scope is deliberately not applied —
 * artifacts may exist without a tenant (orphan rows from ad-hoc connector use),
 * and the retention sweep needs to see every row.
 */
#[Fillable([
    'tenant_id',
    'scrape_run_id',
    'source_kind',
    'source_id',
    'company_id',
    'connector',
    'request_endpoint',
    'http_status',
    'path',
    'size_bytes',
    'sha256',
    'retained_until',
    'fetched_at',
])]
final class ScrapeRunArtifact extends Model
{
    /** @use HasFactory<ScrapeRunArtifactFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'http_status' => 'integer',
            'size_bytes' => 'integer',
            'source_id' => 'integer',
            'retained_until' => 'immutable_datetime',
            'fetched_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<ScrapeRun, $this>
     */
    public function scrapeRun(): BelongsTo
    {
        return $this->belongsTo(ScrapeRun::class);
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
