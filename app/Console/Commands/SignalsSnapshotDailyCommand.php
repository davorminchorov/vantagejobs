<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Company;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;

/**
 * Daily company snapshot writer. One row per (tenant, company, day).
 *
 * Captures the scalar slice that hiring-volume trends, the quality score,
 * and the graveyard view all read from. Other signal jobs (github, blog,
 * layoffs, quality) update the same row in place later in the day; this
 * command's job is just to make sure today's row exists with the
 * static-or-derivable fields populated.
 *
 * Idempotent: re-running for the same UTC day upserts the row.
 *
 * Counts open_role_count from `roles` (where present) and listings_count
 * from `aggregator_listings.matched_company_id`. The roles table is empty
 * today; once it's wired the volume signal becomes meaningful without
 * any changes here.
 */
final class SignalsSnapshotDailyCommand extends Command
{
    protected $signature = 'signals:snapshot:daily
        {--tenant= : Tenant slug}
        {--date= : ISO date (default: today UTC). Use to backfill a single day.}';

    protected $description = 'Upsert today\'s company_snapshots rows for every active company in the tenant.';

    public function __construct(private readonly ConnectionInterface $db)
    {
        parent::__construct();
    }

    public function handle(TenantContext $context): int
    {
        $tenant = $this->resolveTenant();
        if (! $tenant instanceof Tenant) {
            return self::FAILURE;
        }

        $context->setCurrent($tenant);

        $date = $this->resolveDate();
        if ($date === null) {
            return self::FAILURE;
        }

        $startedAt = CarbonImmutable::now();
        $now = $startedAt;

        $companies = Company::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        $upserts = [];
        foreach ($companies as $company) {
            $upserts[] = [
                'tenant_id' => $tenant->id,
                'company_id' => $company->id,
                'snapshot_date' => $date->toDateString(),
                'open_role_count' => $this->openRoleCount($tenant->id, $company->id),
                'listings_count' => $this->listingsCount($tenant->id, $company->id),
                'tier' => $company->tier,
                'status' => $company->status,
                'github_score' => null,
                'blog_posts_90d' => null,
                'has_recent_layoff_event' => false,
                'quality_score' => null,
                'breakdown' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $written = 0;
        if ($upserts !== []) {
            $this->db->table('company_snapshots')->upsert(
                $upserts,
                ['tenant_id', 'company_id', 'snapshot_date'],
                [
                    'open_role_count',
                    'listings_count',
                    'tier',
                    'status',
                    'updated_at',
                ],
            );
            $written = count($upserts);
        }

        $finishedAt = CarbonImmutable::now();
        $this->line((string) json_encode([
            'command' => 'signals:snapshot:daily',
            'tenant' => $tenant->slug,
            'date' => $date->toDateString(),
            'companies' => $written,
            'duration_ms' => $finishedAt->diffInMilliseconds($startedAt, true),
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    private function resolveTenant(): ?Tenant
    {
        $slug = $this->option('tenant');
        if (! is_string($slug) || $slug === '') {
            $this->error('The --tenant=<slug> option is required.');

            return null;
        }

        /** @var Tenant|null $tenant */
        $tenant = Tenant::query()->where('slug', $slug)->first();
        if (! $tenant instanceof Tenant) {
            $this->error("Tenant [{$slug}] not found.");

            return null;
        }

        return $tenant;
    }

    private function resolveDate(): ?CarbonImmutable
    {
        $raw = $this->option('date');
        if (! is_string($raw) || $raw === '') {
            return CarbonImmutable::now('UTC')->startOfDay();
        }
        try {
            return CarbonImmutable::parse($raw, 'UTC')->startOfDay();
        } catch (\Throwable) {
            $this->error("Invalid --date [{$raw}]. Expected ISO date (YYYY-MM-DD).");

            return null;
        }
    }

    private function openRoleCount(int $tenantId, int $companyId): int
    {
        return (int) $this->db->table('roles')
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('is_open', true)
            ->count();
    }

    private function listingsCount(int $tenantId, int $companyId): int
    {
        return (int) $this->db->table('aggregator_listings')
            ->where('tenant_id', $tenantId)
            ->where('matched_company_id', $companyId)
            ->whereIn('status', ['new', 'kept', 'linked'])
            ->count();
    }
}
