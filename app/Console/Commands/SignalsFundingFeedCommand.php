<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\FundingAnnouncement;
use App\Scrapers\Aggregator\CompanyKeyNormalizer;
use App\Signals\Funding\FundedCompanyExtractor;
use App\Signals\Funding\FundingFeedClient;
use App\Signals\Funding\FundingFeedSource;
use App\Signals\Funding\FundingHeadlineFilter;
use App\Signals\Funding\FundingHeadlineParser;
use App\Signals\Funding\IndustryClassifier;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads startup funding news feeds (FundingFeedSource) and stores funding
 * headlines as FundingAnnouncement rows for the Recently funded page.
 *
 * An item is kept when it was published within the last
 * FundingAnnouncement::MAX_AGE_DAYS, names a round or an amount that is not
 * a valuation, and either names who raised ("X raises ...") or contains a
 * funding keyword. Each item also gets industries and a software label
 * from IndustryClassifier. Existing rows get their article fields refreshed;
 * their review state (status, tracked company) is never changed.
 */
final class SignalsFundingFeedCommand extends Command
{
    protected $signature = 'signals:funding:feed
        {--tenant= : Tenant slug}
        {--source= : Only read one source (FundingFeedSource value)}';

    protected $description = 'Read startup funding news feeds and store funding headlines for the Recently funded page.';

    public function __construct(
        private readonly FundingFeedClient $client,
        private readonly FundingHeadlineParser $parser,
        private readonly FundedCompanyExtractor $extractor,
        private readonly FundingHeadlineFilter $filter,
        private readonly CompanyKeyNormalizer $normalizer,
        private readonly IndustryClassifier $classifier,
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

        $sources = FundingFeedSource::cases();
        $only = $this->option('source');
        if (is_string($only) && $only !== '') {
            $source = FundingFeedSource::tryFrom($only);
            if ($source === null) {
                $this->error("Unknown source [{$only}].");

                return self::FAILURE;
            }
            $sources = [$source];
        }

        $startedAt = CarbonImmutable::now();
        $cutoff = $startedAt->subDays(FundingAnnouncement::MAX_AGE_DAYS);
        $stats = ['items' => 0, 'kept' => 0, 'new' => 0, 'errors' => 0];

        foreach ($sources as $source) {
            try {
                $items = $this->client->fetch($source);
            } catch (Throwable $e) {
                $stats['errors']++;
                $this->logger->info('signals.funding.feed_fetch_failed', [
                    'tenant' => $tenant->slug,
                    'source' => $source->value,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            foreach ($items as $item) {
                $stats['items']++;

                if (! $item['published_at'] instanceof CarbonImmutable || $item['published_at']->lessThan($cutoff)) {
                    continue;
                }

                $parsed = $this->parser->parse($item['title'], hasPublisherSuffix: false);
                $companyName = $this->extractor->extract($item['title']);
                $isFundingNews = ($parsed['stage'] !== null || $parsed['amount_label'] !== null)
                    && ($companyName !== null || $this->filter->matchedKeywords($item['title']) !== []);

                if (! $isFundingNews) {
                    continue;
                }
                $stats['kept']++;

                $industry = $this->classifier->classify($item['categories'], $item['title'], $item['summary']);
                $content = [
                    'title' => $item['title'],
                    'url' => $item['url'],
                    'summary' => $item['summary'],
                    'categories' => $item['categories'],
                    'industries' => $industry['industries'],
                    'is_software' => $industry['is_software'],
                    'published_at' => $item['published_at'],
                    'company_name' => $companyName,
                    'company_key' => $this->normalizer->normalize($companyName),
                    'stage' => $parsed['stage'],
                    'amount_usd' => $parsed['amount_usd'],
                    'amount_label' => $parsed['amount_label'],
                ];

                $existing = FundingAnnouncement::query()
                    ->where('source', $source->value)
                    ->where('external_id', $item['external_id'])
                    ->first();
                if ($existing instanceof FundingAnnouncement) {
                    // Refresh what the article says; review state (status,
                    // tracked company) stays as the user left it.
                    $existing->fill($content)->save();

                    continue;
                }

                FundingAnnouncement::query()->create([
                    'source' => $source->value,
                    'external_id' => $item['external_id'],
                    'status' => FundingAnnouncement::STATUS_NEW,
                    ...$content,
                ]);
                $stats['new']++;
            }
        }

        $this->line((string) json_encode([
            'command' => 'signals:funding:feed',
            'tenant' => $tenant->slug,
            'sources' => count($sources),
            ...$stats,
            'duration_ms' => CarbonImmutable::now()->diffInMilliseconds($startedAt, true),
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
