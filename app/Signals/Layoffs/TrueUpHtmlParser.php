<?php

declare(strict_types=1);

namespace App\Signals\Layoffs;

use App\Scrapers\Aggregator\CompanyKeyNormalizer;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Parses the TrueUp /layoffs page into LayoffsRecord instances.
 *
 * TrueUp (SOURCES.md §4.2) is the secondary signal source for layoffs when
 * layoffs.fyi is unavailable. There is no documented API; the page is a
 * Next.js app and the data lives in an embedded `__NEXT_DATA__` JSON blob
 * which we try first. If that shape changes or is absent we degrade to a
 * "no records returned" outcome rather than guessing — wrong matches are
 * worse than silence for negative signals.
 *
 * Records are stamped with `source='trueup'` so they read distinct from
 * layoffs.fyi entries in the Event body.
 */
final class TrueUpHtmlParser
{
    public function __construct(private readonly CompanyKeyNormalizer $normalizer) {}

    /**
     * @return list<LayoffsRecord>
     */
    public function parse(string $html): array
    {
        $json = $this->extractNextDataJson($html);
        if ($json === null) {
            return [];
        }

        $rows = $this->locateLayoffArray($json);
        if ($rows === []) {
            return [];
        }

        $records = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $record = $this->buildRecord($row);
            if ($record !== null) {
                $records[] = $record;
            }
        }

        return $records;
    }

    private function extractNextDataJson(string $html): ?array
    {
        $pattern = '#<script[^>]+id="__NEXT_DATA__"[^>]*>(.*?)</script>#si';
        if (preg_match($pattern, $html, $m) !== 1) {
            return null;
        }
        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode((string) $m[1], true, flags: JSON_THROW_ON_ERROR);

            return $decoded;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Walks the decoded JSON looking for the first array of objects whose
     * elements smell like layoff records — i.e. carry company + date +
     * count-ish fields. TrueUp moves the path between Next builds, so we
     * detect by shape rather than by hard-coded keys.
     *
     * @param  array<string, mixed>  $json
     * @return list<array<string, mixed>>
     */
    private function locateLayoffArray(array $json): array
    {
        $stack = [$json];
        while ($stack !== []) {
            $node = array_pop($stack);
            if (! is_array($node)) {
                continue;
            }

            if (array_is_list($node) && $node !== [] && is_array($node[0])) {
                $sample = $node[0];
                if ($this->smellsLikeLayoffRecord($sample)) {
                    /** @var list<array<string, mixed>> $node */
                    return $node;
                }
            }

            foreach ($node as $child) {
                if (is_array($child)) {
                    $stack[] = $child;
                }
            }
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function smellsLikeLayoffRecord(array $row): bool
    {
        $hasCompany = isset($row['company']) || isset($row['name']) || isset($row['companyName']);
        $hasDate = isset($row['date']) || isset($row['layoffDate']) || isset($row['reported']);
        $hasCount = isset($row['laidOff']) || isset($row['headcount']) || isset($row['employees']);

        return $hasCompany && $hasDate && $hasCount;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function buildRecord(array $row): ?LayoffsRecord
    {
        $name = $this->firstString($row, ['company', 'companyName', 'name']);
        if ($name === null) {
            return null;
        }

        $rawDate = $this->firstString($row, ['date', 'layoffDate', 'reported', 'reportedAt']);
        if ($rawDate === null) {
            return null;
        }
        try {
            $date = CarbonImmutable::parse($rawDate, 'UTC')->startOfDay();
        } catch (Throwable) {
            return null;
        }

        $normalized = $this->normalizer->normalize($name);
        if ($normalized === null) {
            return null;
        }

        return new LayoffsRecord(
            companyName: $name,
            normalizedKey: $normalized,
            headcount: $this->firstInt($row, ['laidOff', 'headcount', 'employees', 'totalLaidOff']),
            percent: $this->firstFloat($row, ['percent', 'percentLaidOff', '%']),
            date: $date,
            industry: $this->firstString($row, ['industry', 'sector']),
            country: $this->firstString($row, ['country', 'hqCountry']),
            stage: $this->firstString($row, ['stage', 'funding']),
            source: 'trueup',
        );
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $keys
     */
    private function firstString(array $row, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $row[$key] ?? null;
            if (is_string($value)) {
                $trimmed = trim($value);
                if ($trimmed !== '') {
                    return $trimmed;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $keys
     */
    private function firstInt(array $row, array $keys): ?int
    {
        foreach ($keys as $key) {
            $value = $row[$key] ?? null;
            if (is_int($value)) {
                return $value;
            }
            if (is_numeric($value)) {
                return (int) $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $keys
     */
    private function firstFloat(array $row, array $keys): ?float
    {
        foreach ($keys as $key) {
            $value = $row[$key] ?? null;
            if (is_float($value) || is_int($value)) {
                $num = (float) $value;
                if ($num <= 1.0 && $num > 0) {
                    $num *= 100.0;
                }

                return round($num, 2);
            }
            if (is_string($value) && is_numeric($value)) {
                $num = (float) $value;
                if ($num <= 1.0 && $num > 0) {
                    $num *= 100.0;
                }

                return round($num, 2);
            }
        }

        return null;
    }
}
