<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Company;
use App\Signals\Quality\QualityScorer;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;

/**
 * Recompute the company quality_score + breakdown for every active
 * company and persist it on today's company_snapshots row.
 *
 * Runs after the snapshot, github, blog, layoffs, and ghost jobs so
 * every input is fresh. Idempotent: an upsert keyed on (tenant_id,
 * company_id, snapshot_date) overwrites in place.
 */
final class SignalsQualityRecomputeCommand extends Command
{
    protected $signature = 'signals:quality:recompute
        {--tenant= : Tenant slug}
        {--company= : Restrict to a single company id}';

    protected $description = 'Compute quality_score + breakdown for active companies and write to today\'s snapshot row.';

    public function __construct(
        private readonly QualityScorer $scorer,
        private readonly ConnectionInterface $db,
    ) {
        parent::__construct();
    }

    public function handle(TenantContext $context): int
    {
        $tenant = $this->resolveTenant();
        if (! $tenant instanceof Tenant) {
            return self::FAILURE;
        }
        $context->setCurrent($tenant);

        $startedAt = CarbonImmutable::now();
        $today = $startedAt->utc()->startOfDay();
        $now = $startedAt;

        $companyId = $this->option('company');
        $query = Company::query()->where('is_active', true)->orderBy('id');
        if (is_string($companyId) && $companyId !== '') {
            $query->where('id', (int) $companyId);
        }

        $updates = [];
        $companies = $query->get();
        foreach ($companies as $company) {
            $result = $this->scorer->compute($company);
            $updates[] = [
                'tenant_id' => $tenant->id,
                'company_id' => $company->id,
                'snapshot_date' => $today->toDateString(),
                'open_role_count' => 0,
                'listings_count' => 0,
                'tier' => $company->tier,
                'status' => $company->status,
                'quality_score' => $result['score'],
                'breakdown' => json_encode($result['breakdown']),
                'has_recent_layoff_event' => $result['breakdown']['layoff_penalty']['value']['within_90d'] ?? false,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $written = 0;
        if ($updates !== []) {
            $this->db->table('company_snapshots')->upsert(
                $updates,
                ['tenant_id', 'company_id', 'snapshot_date'],
                ['quality_score', 'breakdown', 'has_recent_layoff_event', 'tier', 'status', 'updated_at'],
            );
            $written = count($updates);
        }

        $finishedAt = CarbonImmutable::now();
        $this->line((string) json_encode([
            'command' => 'signals:quality:recompute',
            'tenant' => $tenant->slug,
            'companies' => $written,
            'date' => $today->toDateString(),
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
}
