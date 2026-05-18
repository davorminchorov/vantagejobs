<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator;

use App\Enums\AggregatorSourceType;
use App\Models\AggregatorListing;
use App\Scrapers\Aggregator\Adzuna\AdzunaContextBuilder;
use App\Scrapers\Aggregator\Arbeitnow\ArbeitnowContextBuilder;
use App\Scrapers\Aggregator\DjangoNews\DjangoNewsContextBuilder;
use App\Scrapers\Aggregator\ElixirJobs\ElixirJobsContextBuilder;
use App\Scrapers\Aggregator\FourDayWeek\FourDayWeekContextBuilder;
use App\Scrapers\Aggregator\HackerNews\HackerNewsContextBuilder;
use App\Scrapers\Aggregator\HexHire\HexHireContextBuilder;
use App\Scrapers\Aggregator\Himalayas\HimalayasContextBuilder;
use App\Scrapers\Aggregator\Jobicy\JobicyContextBuilder;
use App\Scrapers\Aggregator\Larajobs\LarajobsContextBuilder;
use App\Scrapers\Aggregator\Parser\SourceContext;
use App\Scrapers\Aggregator\PythonOrg\PythonOrgContextBuilder;
use App\Scrapers\Aggregator\RemoteOk\RemoteOkContextBuilder;
use App\Scrapers\Aggregator\Remotive\RemotiveContextBuilder;
use App\Scrapers\Aggregator\WeWorkRemotely\WeWorkRemotelyContextBuilder;
use App\Scrapers\Aggregator\WorkingNomads\WorkingNomadsContextBuilder;

/**
 * Builds a SourceContext from an AggregatorListing's raw payload, for use
 * outside the scrape-time hot path (Phase 3 re-parse + save-as-fixture
 * actions).
 *
 * Each context builder lives in its scraper namespace -- this factory
 * picks the right one from the listing's source type. Builders are pure
 * (no HTTP, no DB), so re-running them from a stored raw record produces
 * the same SourceContext the original scrape produced.
 */
final readonly class SourceContextFactory
{
    public function __construct(
        private HackerNewsContextBuilder $hackerNews,
        private RemotiveContextBuilder $remotive,
        private HimalayasContextBuilder $himalayas,
        private WeWorkRemotelyContextBuilder $weWorkRemotely,
        private RemoteOkContextBuilder $remoteOk,
        private JobicyContextBuilder $jobicy,
        private ArbeitnowContextBuilder $arbeitnow,
        private PythonOrgContextBuilder $pythonOrg,
        private DjangoNewsContextBuilder $djangoNews,
        private ElixirJobsContextBuilder $elixirJobs,
        private HexHireContextBuilder $hexHire,
        private FourDayWeekContextBuilder $fourDayWeek,
        private WorkingNomadsContextBuilder $workingNomads,
        private AdzunaContextBuilder $adzuna,
        private LarajobsContextBuilder $larajobs,
    ) {}

    public function buildFor(AggregatorListing $listing): SourceContext
    {
        $listing->loadMissing('source');
        $source = $listing->source;
        if ($source === null) {
            throw new \RuntimeException("Listing #{$listing->id} has no source.");
        }

        /** @var array<string, mixed> $raw */
        $raw = $listing->raw ?? [];

        return match ($source->type) {
            AggregatorSourceType::HACKERNEWS => $this->hackerNews->build($this->asHackerNewsComment($raw)),
            AggregatorSourceType::REMOTIVE => $this->remotive->build($raw),
            AggregatorSourceType::HIMALAYAS => $this->himalayas->build($raw),
            AggregatorSourceType::WEWORKREMOTELY => $this->weWorkRemotely->build($raw),
            AggregatorSourceType::REMOTEOK => $this->remoteOk->build($raw),
            AggregatorSourceType::JOBICY => $this->jobicy->build($raw),
            AggregatorSourceType::ARBEITNOW => $this->arbeitnow->build($raw),
            AggregatorSourceType::PYTHON_ORG => $this->pythonOrg->build($raw),
            AggregatorSourceType::DJANGO_NEWS => $this->djangoNews->build($raw),
            AggregatorSourceType::ELIXIR_JOBS => $this->elixirJobs->build($raw),
            AggregatorSourceType::HEXHIRE => $this->hexHire->build($raw),
            AggregatorSourceType::FOURDAYWEEK => $this->fourDayWeek->build($raw),
            AggregatorSourceType::WORKINGNOMADS => $this->workingNomads->build($raw),
            AggregatorSourceType::ADZUNA => $this->adzuna->build($raw),
            AggregatorSourceType::LARAJOBS => $this->larajobs->build($raw),
        };
    }

    /**
     * HackerNewsContextBuilder::build expects a typed associative shape;
     * cast loose raw input into it.
     *
     * @param  array<string, mixed>  $raw
     * @return array{id?: int|string, author?: string, text?: string, created_at?: string}
     */
    private function asHackerNewsComment(array $raw): array
    {
        $out = [];
        if (isset($raw['id']) && (is_string($raw['id']) || is_int($raw['id']))) {
            $out['id'] = $raw['id'];
        }
        if (isset($raw['author']) && is_string($raw['author'])) {
            $out['author'] = $raw['author'];
        }
        if (isset($raw['text']) && is_string($raw['text'])) {
            $out['text'] = $raw['text'];
        }
        if (isset($raw['created_at']) && is_string($raw['created_at'])) {
            $out['created_at'] = $raw['created_at'];
        }

        return $out;
    }
}
