<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\Adzuna;

use App\Enums\AggregatorSourceType;
use App\Models\AggregatorSource;
use App\Scrapers\Aggregator\Adzuna\Requests\SearchRequest;
use App\Scrapers\Aggregator\AggregatorListingPersister;
use App\Scrapers\Aggregator\AggregatorRunResult;
use App\Scrapers\Aggregator\Contracts\AggregatorScraperInterface;
use Throwable;

/**
 * Adzuna IT-jobs scraper.
 *
 * Per SOURCES.md §3.8: authenticated REST API, one country at a time, paged.
 * We iterate every country in `scrapers.aggregators.adzuna.countries` (e.g.
 * `gb,de,nl,fr,at,ch,pl`) up to `pages_per_country` pages, breaking early
 * when a short page (< 50 results) signals end-of-list. Each result is
 * upserted via the shared persister.
 *
 * The connector requires app_id + app_key; if either is missing the run
 * returns an error result rather than calling the API with empty creds.
 */
final readonly class AdzunaScraper implements AggregatorScraperInterface
{
    private const RESULTS_PER_PAGE = 50;

    public function __construct(
        private AdzunaContextBuilder $contextBuilder,
        private AggregatorListingPersister $persister,
    ) {}

    public function name(): AggregatorSourceType
    {
        return AggregatorSourceType::ADZUNA;
    }

    public function concurrency(): int
    {
        return (int) config('scrapers.aggregators.adzuna.concurrency');
    }

    public function run(AggregatorSource $source): AggregatorRunResult
    {
        $appId = (string) config('scrapers.aggregators.adzuna.app_id');
        if ($appId === '') {
            return new AggregatorRunResult(
                fetched: 0,
                newCount: 0,
                errors: [['message' => 'Adzuna credentials not configured (set ADZUNA_APP_ID/ADZUNA_APP_KEY).']],
            );
        }

        $countries = $this->parseCountries((string) config('scrapers.aggregators.adzuna.countries'));
        $pagesPerCountry = (int) config('scrapers.aggregators.adzuna.pages_per_country', 3);

        $fetched = 0;
        $createdIds = [];
        $errors = [];

        try {
            $connector = new AdzunaConnector();

            foreach ($countries as $country) {
                for ($page = 1; $page <= $pagesPerCountry; $page++) {
                    try {
                        $response = $connector->send(new SearchRequest($country, $page));
                    } catch (Throwable $e) {
                        $errors[] = [
                            'message' => $e->getMessage(),
                            'context' => ['country' => $country, 'page' => $page],
                        ];
                        break;
                    }

                    if ($response->status() >= 400) {
                        $errors[] = [
                            'message' => 'Adzuna HTTP '.$response->status(),
                            'context' => ['country' => $country, 'page' => $page],
                        ];
                        break;
                    }

                    $payload = $response->json();
                    $results = is_array($payload['results'] ?? null) ? $payload['results'] : [];

                    foreach ($results as $result) {
                        if (! is_array($result)) {
                            continue;
                        }
                        $externalId = isset($result['id']) ? (string) $result['id'] : '';
                        if ($externalId === '') {
                            continue;
                        }

                        $fetched++;

                        try {
                            $context = $this->contextBuilder->build($result, $country);
                            $raw = $context->structured;
                            $id = $this->persister->upsert($source, $context, $raw, $externalId);
                            if ($id !== null) {
                                $createdIds[] = $id;
                            }
                        } catch (Throwable $e) {
                            $errors[] = [
                                'message' => $e->getMessage(),
                                'context' => [
                                    'country' => $country,
                                    'page' => $page,
                                    'job_id' => $externalId,
                                ],
                            ];
                        }
                    }

                    if (count($results) < self::RESULTS_PER_PAGE) {
                        break;
                    }
                }
            }
        } catch (Throwable $e) {
            return new AggregatorRunResult(
                fetched: $fetched,
                newCount: count($createdIds),
                errors: array_merge($errors, [['message' => $e->getMessage()]]),
            );
        }

        $this->persister->capRun($createdIds);

        return new AggregatorRunResult(
            fetched: $fetched,
            newCount: count($createdIds),
            errors: $errors,
        );
    }

    /**
     * @return list<string>
     */
    private function parseCountries(string $raw): array
    {
        $out = [];
        foreach (explode(',', $raw) as $piece) {
            $code = strtolower(trim($piece));
            if ($code !== '') {
                $out[] = $code;
            }
        }

        return $out;
    }
}
