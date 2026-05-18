<?php

declare(strict_types=1);

namespace App\Signals\Layoffs;

use App\Models\Company;
use App\Models\Event;
use App\Scrapers\Aggregator\CompanyKeyNormalizer;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * Cross-references a stream of LayoffsRecords against the tenant's tracked
 * companies, emitting `layoff` events on matches.
 *
 * Idempotent: each (company, date, source) tuple produces a sha1 signature
 * embedded in the event body JSON. Re-running the sync against the same CSV
 * is a no-op.
 *
 * We deliberately don't flip Company.is_active here. Layoffs are signals,
 * not death sentences — the operator decides graveyard status.
 */
final readonly class LayoffsSync
{
    public function __construct(
        private CompanyKeyNormalizer $normalizer,
        private TenantContext $context,
    ) {}

    /**
     * @param  iterable<LayoffsRecord>  $records
     * @return array{matched: int, created: int, duplicate: int, unmatched: int}
     */
    public function sync(iterable $records): array
    {
        $tenant = $this->context->currentOrFail();

        $companyKeyMap = $this->buildCompanyKeyMap($tenant->id);

        $matched = 0;
        $created = 0;
        $duplicate = 0;
        $unmatched = 0;

        foreach ($records as $record) {
            $companyId = $companyKeyMap[$record->normalizedKey] ?? null;
            if ($companyId === null) {
                $unmatched++;

                continue;
            }
            $matched++;

            $signature = sha1(sprintf(
                '%d|%s|%s',
                $companyId,
                $record->date->toDateString(),
                $record->source,
            ));

            $exists = Event::query()
                ->where('company_id', $companyId)
                ->where('type', 'layoff')
                ->where('body', 'like', '%"signature":"'.$signature.'"%')
                ->exists();

            if ($exists) {
                $duplicate++;

                continue;
            }

            Event::query()->create([
                'company_id' => $companyId,
                'type' => 'layoff',
                'title' => $this->formatTitle($record),
                'body' => (string) json_encode([
                    'headcount' => $record->headcount,
                    'percent' => $record->percent,
                    'date' => $record->date->toDateString(),
                    'source' => $record->source,
                    'industry' => $record->industry,
                    'country' => $record->country,
                    'stage' => $record->stage,
                    'signature' => $signature,
                ], JSON_THROW_ON_ERROR),
                'occurred_at' => $record->date,
            ]);
            $created++;
        }

        return [
            'matched' => $matched,
            'created' => $created,
            'duplicate' => $duplicate,
            'unmatched' => $unmatched,
        ];
    }

    /**
     * @return array<string, int> normalized_key => company_id
     */
    private function buildCompanyKeyMap(int $tenantId): array
    {
        $map = [];
        Company::query()
            ->where('tenant_id', $tenantId)
            ->select(['id', 'name'])
            ->orderBy('id')
            ->each(function (Company $company) use (&$map): void {
                $key = $this->normalizer->normalize($company->name);
                if ($key === null) {
                    return;
                }
                // First-write-wins keeps the lowest-id company on duplicate
                // normalised names.
                if (! isset($map[$key])) {
                    $map[$key] = (int) $company->id;
                }
            });

        return $map;
    }

    private function formatTitle(LayoffsRecord $record): string
    {
        $head = $record->headcount === null ? '?' : (string) $record->headcount;
        $pct = $record->percent === null ? '?' : rtrim(rtrim(number_format($record->percent, 2, '.', ''), '0'), '.');

        return sprintf('Layoff: %s / %s%%', $head, $pct);
    }

    /**
     * Cheap helper for callers that want to log a stamp.
     */
    public function timestamp(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC');
    }
}
