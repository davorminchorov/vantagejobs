<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\FundingCandidate;
use App\Signals\Funding\FundingFetchException;
use App\Signals\Funding\GoogleNewsClient;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Weekly per-company scan of Google News RSS for funding keywords.
 * Each plausible hit becomes a CANDIDATE funding event stored in the
 * funding_candidates review queue. No Company funding scalars are
 * touched — a user confirms in the UI to promote a candidate into a
 * real funding Event. See PRD §6.3.5, SOURCES §4.5.
 *
 * Idempotent on (tenant_id, company_id, source, external_id).
 */
final class SignalsFundingNewsCommand extends Command
{
    protected $signature = 'signals:funding:news
        {--tenant= : Tenant slug}
        {--company= : Only scan a single company (id)}';

    protected $description = 'Scan Google News RSS for funding-related articles about tracked companies and enqueue review candidates.';

    public function __construct(
        private readonly GoogleNewsClient $client,
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

        $query = Company::query()
            ->where('is_active', true)
            ->orderBy('id');

        $companyFilter = $this->option('company');
        if (is_string($companyFilter) && $companyFilter !== '') {
            $query->where('id', (int) $companyFilter);
        }

        /** @var iterable<int, Company> $companies */
        $companies = $query->get();

        $companiesScanned = 0;
        $candidatesNew = 0;
        $candidatesTotal = 0;
        $errors = 0;

        foreach ($companies as $company) {
            $companiesScanned++;

            $name = (string) $company->getAttribute('name');
            if ($name === '') {
                continue;
            }

            try {
                $items = $this->client->search($name);
            } catch (FundingFetchException $e) {
                $errors++;
                $this->logger->info('signals.funding.news_fetch_failed', [
                    'tenant' => $tenant->slug,
                    'company_id' => $company->id,
                    'company_name' => $name,
                    'error' => $e->getMessage(),
                ]);

                continue;
            } catch (Throwable $e) {
                $errors++;
                $this->logger->info('signals.funding.news_fetch_failed', [
                    'tenant' => $tenant->slug,
                    'company_id' => $company->id,
                    'company_name' => $name,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            $candidatesTotal += count($items);

            foreach ($items as $item) {
                if ($this->persistCandidate($company, $item)) {
                    $candidatesNew++;
                }
            }
        }

        $finishedAt = CarbonImmutable::now();

        $this->line((string) json_encode([
            'command' => 'signals:funding:news',
            'tenant' => $tenant->slug,
            'companies_scanned' => $companiesScanned,
            'candidates_new' => $candidatesNew,
            'candidates_total' => $candidatesTotal,
            'errors' => $errors,
            'duration_ms' => $finishedAt->diffInMilliseconds($startedAt, true),
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /**
     * @param  array{external_id: string, title: string, url: string, published_at: CarbonImmutable|null, matched: list<string>}  $item
     */
    private function persistCandidate(Company $company, array $item): bool
    {
        $now = CarbonImmutable::now();

        $existing = FundingCandidate::query()
            ->where('company_id', $company->id)
            ->where('source', 'google_news')
            ->where('external_id', $item['external_id'])
            ->first();

        if ($existing instanceof FundingCandidate) {
            $existing->fill([
                'title' => $item['title'],
                'url' => $item['url'],
                'published_at' => $item['published_at'],
                'matched_keywords' => $item['matched'],
                'fetched_at' => $now,
            ])->save();

            return false;
        }

        $candidate = new FundingCandidate;
        $candidate->fill([
            'company_id' => $company->id,
            'source' => 'google_news',
            'external_id' => $item['external_id'],
            'title' => $item['title'],
            'url' => $item['url'],
            'published_at' => $item['published_at'],
            'matched_keywords' => $item['matched'],
            'status' => FundingCandidate::STATUS_NEW,
            'fetched_at' => $now,
        ])->save();

        return true;
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
