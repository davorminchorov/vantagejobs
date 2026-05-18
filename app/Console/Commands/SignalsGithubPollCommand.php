<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Company;
use App\Signals\Github\GithubFetchException;
use App\Signals\Github\GithubOrgScorer;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Weekly GitHub org poller.
 *
 * Iterates active companies with a non-null github_org, fetches a
 * 0-100 "eng activity" score via GithubOrgScorer, and stamps it onto
 * TODAY's company_snapshots row (upsert keyed on tenant_id, company_id,
 * snapshot_date).
 *
 * Per-org failures (404/403/timeout) are isolated so one bad org never
 * stops the rest. Sleeps 100ms between orgs to stay polite even though
 * the authenticated rate-limit budget is 5000/h.
 */
final class SignalsGithubPollCommand extends Command
{
    protected $signature = 'signals:github:poll
        {--tenant= : Tenant slug}
        {--company= : Limit run to a single company id}';

    protected $description = 'Poll GitHub org activity for each active company and write github_score onto today\'s snapshot row.';

    private const int SLEEP_MICROSECONDS = 100_000;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly GithubOrgScorer $scorer,
        private readonly LoggerInterface $logger,
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
        $today = CarbonImmutable::now('UTC')->startOfDay()->toDateString();

        $query = Company::query()
            ->where('is_active', true)
            ->whereNotNull('github_org');

        $companyOption = $this->option('company');
        if (is_string($companyOption) && $companyOption !== '') {
            $query->where('id', (int) $companyOption);
        }

        $companies = $query->orderBy('id')->get();

        $processed = 0;
        $scored = 0;
        $failed = 0;
        $first = true;

        foreach ($companies as $company) {
            if (! $first) {
                usleep(self::SLEEP_MICROSECONDS);
            }
            $first = false;

            $processed++;
            $org = (string) $company->github_org;

            try {
                $result = $this->scorer->score($org);
            } catch (GithubFetchException $e) {
                $failed++;
                $this->logger->warning('signals.github.poll.failed', [
                    'tenant' => $tenant->slug,
                    'company_id' => $company->id,
                    'github_org' => $org,
                    'reason' => $e->getMessage(),
                ]);

                continue;
            } catch (Throwable $e) {
                $failed++;
                $this->logger->error('signals.github.poll.error', [
                    'tenant' => $tenant->slug,
                    'company_id' => $company->id,
                    'github_org' => $org,
                    'reason' => $e->getMessage(),
                ]);

                continue;
            }

            $now = CarbonImmutable::now();
            $this->db->table('company_snapshots')->upsert(
                [[
                    'tenant_id' => $tenant->id,
                    'company_id' => $company->id,
                    'snapshot_date' => $today,
                    'open_role_count' => 0,
                    'listings_count' => 0,
                    'tier' => $company->tier,
                    'status' => $company->status,
                    'github_score' => $result['score'],
                    'blog_posts_90d' => null,
                    'has_recent_layoff_event' => false,
                    'quality_score' => null,
                    'breakdown' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]],
                ['tenant_id', 'company_id', 'snapshot_date'],
                ['github_score', 'updated_at'],
            );
            $scored++;
        }

        $finishedAt = CarbonImmutable::now();
        $this->line((string) json_encode([
            'command' => 'signals:github:poll',
            'tenant' => $tenant->slug,
            'date' => $today,
            'companies_processed' => $processed,
            'companies_scored' => $scored,
            'companies_failed' => $failed,
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
