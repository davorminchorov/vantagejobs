<?php

declare(strict_types=1);

namespace App\Signals\Ats;

use App\Models\Company;
use App\Scrapers\AtsDetector;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Re-runs ATS auto-detection for a company whose existing scraper has gone
 * dark (PRD §11.2). Inspects the careers page URL itself plus the rendered
 * HTML (iframe embeds, anchor hrefs) for fingerprints from any supported
 * ATS, returning the detected ATS slug or null if nothing matches.
 *
 * Detection is intentionally a thin layer over {@see AtsDetector}: the
 * detector already owns the regex patterns; this service is responsible
 * only for sourcing the candidate URLs (the careers page itself + any
 * iframes / hrefs it contains).
 */
final readonly class AtsRedetector
{
    public function __construct(
        private AtsDetector $detector,
        private HttpFactory $http,
        private LoggerInterface $logger,
    ) {}

    /**
     * @return ?array{ats_type: string, ats_slug: string, ats_host?: string}
     */
    public function redetect(Company $company): ?array
    {
        $careersUrl = is_string($company->careers_url) ? $company->careers_url : null;
        if ($careersUrl === null || $careersUrl === '') {
            return null;
        }

        // First pass: the URL itself may already encode the ATS (e.g. the
        // company switched ATS by redirecting careers_url to the new board).
        $fromUrl = $this->detector->detect($careersUrl);
        if ($fromUrl !== null) {
            return $fromUrl;
        }

        // Second pass: fetch the page and scan the body for ATS fingerprints.
        try {
            $response = $this->http
                ->withHeaders(['User-Agent' => 'VantageJobs ATS-redetect/1.0'])
                ->timeout(15)
                ->get($careersUrl);
        } catch (ConnectionException $e) {
            $this->logger->info('signals.ats.redetect.fetch_failed', [
                'company_id' => $company->id,
                'careers_url' => $careersUrl,
                'error' => $e->getMessage(),
            ]);

            return null;
        } catch (Throwable $e) {
            $this->logger->info('signals.ats.redetect.fetch_failed', [
                'company_id' => $company->id,
                'careers_url' => $careersUrl,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $body = (string) $response->body();
        if ($body === '') {
            return null;
        }

        // Walk every URL-shaped token in the body and feed each to the
        // detector. First match wins.
        if (preg_match_all('#https?://[^\s"\'<>]+#i', $body, $matches) === false) {
            return null;
        }

        foreach ($matches[0] as $url) {
            $hit = $this->detector->detect($url);
            if ($hit !== null) {
                return $hit;
            }
        }

        return null;
    }
}
