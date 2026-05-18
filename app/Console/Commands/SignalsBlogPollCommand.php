<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\EventType;
use App\Models\Company;
use App\Models\CompanyBlogPost;
use App\Models\Event;
use App\Signals\Blog\BlogFeedFetcher;
use App\Signals\Blog\FeedFetchException;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Daily eng-blog RSS / Atom poll. Stores new posts under
 * `company_blog_posts` and emits a `blog_post` Event for each truly
 * new row so the company timeline picks them up.
 *
 * Idempotent on (tenant_id, company_id, external_id). Per PRD §6.3.4,
 * the cadence signal (posts / 90 days) is read by the quality-score
 * job from the row count, so this command's only job is "keep the
 * table fresh".
 *
 * Per-company fetch failures don't abort the run — other companies
 * continue. On 14 consecutive failures for a single company a
 * structured warning is logged (email alerts come later).
 */
final class SignalsBlogPollCommand extends Command
{
    /**
     * Number of consecutive failures that promotes a warning into the log.
     * Matches the dead-feed threshold called out by the spec.
     */
    private const FAILURE_WARN_THRESHOLD = 14;

    protected $signature = 'signals:blog:poll
        {--tenant= : Tenant slug}
        {--company= : Only poll a single company (id)}';

    protected $description = 'Poll engineering-blog feeds for every active company with eng_blog_rss_url set.';

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly BlogFeedFetcher $fetcher,
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
            ->whereNotNull('eng_blog_rss_url')
            ->orderBy('id');

        $companyFilter = $this->option('company');
        if (is_string($companyFilter) && $companyFilter !== '') {
            $query->where('id', (int) $companyFilter);
        }

        /** @var iterable<int, Company> $companies */
        $companies = $query->get();

        $companiesPolled = 0;
        $postsNew = 0;
        $postsTotal = 0;
        $failures = 0;

        foreach ($companies as $company) {
            $companiesPolled++;

            $url = (string) $company->getAttribute('eng_blog_rss_url');
            if ($url === '') {
                continue;
            }

            try {
                $items = $this->fetcher->fetch($url);
            } catch (FeedFetchException $e) {
                $failures++;
                $this->handleFailure($tenant, $company, $url, $e);

                continue;
            } catch (Throwable $e) {
                $failures++;
                $this->handleFailure($tenant, $company, $url, $e);

                continue;
            }

            $postsTotal += count($items);

            foreach ($items as $item) {
                if ($this->persistItem($tenant, $company, $item)) {
                    $postsNew++;
                }
            }
        }

        $finishedAt = CarbonImmutable::now();

        $this->line((string) json_encode([
            'command' => 'signals:blog:poll',
            'tenant' => $tenant->slug,
            'companies_polled' => $companiesPolled,
            'posts_new' => $postsNew,
            'posts_total' => $postsTotal,
            'failures' => $failures,
            'duration_ms' => $finishedAt->diffInMilliseconds($startedAt, true),
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /**
     * @param  array{external_id: string, title: string, url: string, published_at: CarbonImmutable|null, excerpt: string|null}  $item
     */
    private function persistItem(Tenant $tenant, Company $company, array $item): bool
    {
        $now = CarbonImmutable::now();

        $existing = CompanyBlogPost::query()
            ->where('company_id', $company->id)
            ->where('external_id', $item['external_id'])
            ->first();

        if ($existing instanceof CompanyBlogPost) {
            $existing->fill([
                'title' => $item['title'],
                'url' => $item['url'],
                'published_at' => $item['published_at'],
                'excerpt' => $item['excerpt'],
                'fetched_at' => $now,
            ])->save();

            return false;
        }

        $post = new CompanyBlogPost;
        $post->fill([
            'company_id' => $company->id,
            'external_id' => $item['external_id'],
            'title' => $item['title'],
            'url' => $item['url'],
            'published_at' => $item['published_at'],
            'excerpt' => $item['excerpt'],
            'fetched_at' => $now,
        ])->save();

        Event::query()->create([
            'company_id' => $company->id,
            'role_id' => null,
            'type' => EventType::BLOG_POST->value,
            'title' => $item['title'],
            'body' => (string) json_encode([
                'url' => $item['url'],
                'published_at' => $item['published_at']?->toIso8601String(),
            ], JSON_THROW_ON_ERROR),
            'occurred_at' => $item['published_at'] ?? $now,
        ]);

        return true;
    }

    private function handleFailure(Tenant $tenant, Company $company, string $url, Throwable $error): void
    {
        $consecutive = $this->consecutiveFailureCount($tenant->id, $company->id);

        $context = [
            'tenant' => $tenant->slug,
            'company_id' => $company->id,
            'company_name' => $company->getAttribute('name'),
            'feed_url' => $url,
            'error' => $error->getMessage(),
        ];

        if ($consecutive >= self::FAILURE_WARN_THRESHOLD) {
            $this->logger->warning(
                'signals.blog.feed_unreachable',
                $context + ['consecutive_failures' => $consecutive],
            );

            return;
        }

        $this->logger->info('signals.blog.feed_fetch_failed', $context);
    }

    /**
     * Approximates the "consecutive failures" notion by counting how long it's
     * been since this company saw a fresh row. A real run-tracker can replace
     * this when the GitHub poller lands its shared `signal_runs` table — until
     * then, days-since-last-post is a usable proxy for "is this feed dead?".
     */
    private function consecutiveFailureCount(int $tenantId, int $companyId): int
    {
        $latest = $this->db->table('company_blog_posts')
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->max('fetched_at');

        if ($latest === null) {
            // Never seen a post. Treat as 14+ so the first run fails loudly
            // only after the run-tracker shows the feed has been down a while —
            // not on day 1.
            return 1;
        }

        $latestAt = CarbonImmutable::parse((string) $latest);

        return (int) $latestAt->diffInDays(CarbonImmutable::now(), true);
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
