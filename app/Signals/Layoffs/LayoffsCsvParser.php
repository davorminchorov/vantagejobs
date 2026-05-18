<?php

declare(strict_types=1);

namespace App\Signals\Layoffs;

use App\Scrapers\Aggregator\CompanyKeyNormalizer;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Parses the Kaggle mirror CSV for layoffs.fyi (dataset
 * `theakhilb/layoffs-data-2022`, see SOURCES §4.1).
 *
 * Expected columns (order-independent, case-insensitive):
 *   Company, Location_HQ, # Laid Off, %, Date, Industry, Source,
 *   Stage, Country, Funds_Raised
 *
 * Robust to missing columns: rows lacking Company or Date are skipped.
 * Percent values may be "0.10", "10%", or "10". The parser normalises
 * them to a float in 0..100 range.
 */
final class LayoffsCsvParser
{
    public function __construct(private readonly CompanyKeyNormalizer $normalizer) {}

    /**
     * @return list<LayoffsRecord>
     */
    public function parse(string $csv, string $source = 'layoffs_fyi'): array
    {
        $csv = ltrim($csv, "\xEF\xBB\xBF");
        $lines = preg_split("/\r\n|\n|\r/", $csv) ?: [];
        $lines = array_values(array_filter($lines, static fn (string $l): bool => $l !== ''));
        if ($lines === []) {
            return [];
        }

        $header = str_getcsv((string) array_shift($lines), ',', '"', '\\');
        $header = array_map(
            static fn ($cell): string => strtolower(trim((string) $cell)),
            $header,
        );

        $columnIndex = static function (array $names) use ($header): ?int {
            foreach ($names as $name) {
                $idx = array_search(strtolower($name), $header, true);
                if ($idx !== false) {
                    return (int) $idx;
                }
            }

            return null;
        };

        $iCompany = $columnIndex(['company']);
        $iLaidOff = $columnIndex(['# laid off', 'laid_off', 'total_laid_off', 'laid off']);
        $iPercent = $columnIndex(['%', 'percentage_laid_off', 'percent']);
        $iDate = $columnIndex(['date']);
        $iIndustry = $columnIndex(['industry']);
        $iCountry = $columnIndex(['country']);
        $iStage = $columnIndex(['stage']);

        if ($iCompany === null || $iDate === null) {
            return [];
        }

        $records = [];
        foreach ($lines as $line) {
            $row = str_getcsv($line, ',', '"', '\\');
            $name = isset($row[$iCompany]) ? trim((string) $row[$iCompany]) : '';
            if ($name === '') {
                continue;
            }
            $rawDate = isset($row[$iDate]) ? trim((string) $row[$iDate]) : '';
            if ($rawDate === '') {
                continue;
            }
            try {
                $date = CarbonImmutable::parse($rawDate, 'UTC')->startOfDay();
            } catch (Throwable) {
                continue;
            }

            $normalized = $this->normalizer->normalize($name);
            if ($normalized === null) {
                continue;
            }

            $records[] = new LayoffsRecord(
                companyName: $name,
                normalizedKey: $normalized,
                headcount: $this->parseInt($row[$iLaidOff] ?? null),
                percent: $this->parsePercent($row[$iPercent] ?? null),
                date: $date,
                industry: $this->parseString($row[$iIndustry] ?? null),
                country: $this->parseString($row[$iCountry] ?? null),
                stage: $this->parseString($row[$iStage] ?? null),
                source: $source,
            );
        }

        return $records;
    }

    private function parseInt(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string) $value);
        if ($trimmed === '' || ! is_numeric($trimmed)) {
            return null;
        }

        return (int) $trimmed;
    }

    private function parsePercent(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            return null;
        }
        $hadPercent = str_contains($trimmed, '%');
        $clean = str_replace('%', '', $trimmed);
        if (! is_numeric($clean)) {
            return null;
        }
        $num = (float) $clean;
        // Kaggle source stores fractions ("0.10" = 10%). Normalise to 0..100.
        if (! $hadPercent && $num <= 1.0 && $num > 0) {
            $num *= 100.0;
        }

        return round($num, 2);
    }

    private function parseString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
