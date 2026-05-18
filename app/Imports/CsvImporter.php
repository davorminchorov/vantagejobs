<?php

declare(strict_types=1);

namespace App\Imports;

use App\Enums\EventType;
use App\Models\Company;
use App\Models\Event;
use App\Scrapers\AtsDetector;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use SplFileObject;

/**
 * CSV import for Companies.
 *
 * Lifecycle: parse(SplFileObject) -> headers + rows kept in session.
 * Then run(mapping, rows) -> creates Company rows idempotently on
 * (tenant_id, domain). Re-running with the same CSV is a no-op for
 * Companies already on the list.
 */
final readonly class CsvImporter
{
    public const MAX_ROWS = 5000;

    public const FIELDS = ['name', 'website', 'careers_url', 'domain', 'tier', 'status'];

    public const REQUIRED_FIELDS = ['name'];

    public function __construct(private AtsDetector $atsDetector) {}

    /**
     * Parse a CSV file into headers + raw rows.
     *
     * @return array{headers: list<string>, rows: list<list<string>>}
     */
    public function parse(SplFileObject $file): array
    {
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::READ_AHEAD);

        $headers = [];
        $rows = [];
        foreach ($file as $line) {
            if (! is_array($line) || $line === [null]) {
                continue;
            }
            if ($headers === []) {
                $headers = array_map(fn ($h) => trim((string) $h), $line);

                continue;
            }
            $rows[] = array_map(fn ($v) => is_string($v) ? trim($v) : (string) ($v ?? ''), $line);
            if (count($rows) >= self::MAX_ROWS) {
                break;
            }
        }

        if ($headers === []) {
            throw ValidationException::withMessages(['file' => 'CSV is empty or has no header row.']);
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * Heuristic mapping from CSV headers to canonical company fields.
     *
     * @param  list<string>  $headers
     * @return array<string, ?string>  field => header (or null when no guess)
     */
    public function detectMapping(array $headers): array
    {
        $patterns = [
            'name' => '/^(name|company|company.?name|organi[sz]ation)$/i',
            'website' => '/^(website|url|site|homepage|web)$/i',
            'careers_url' => '/^(careers|careers.?url|jobs.?page|careers.?page)$/i',
            'domain' => '/^domain$/i',
            'tier' => '/^(tier|priority|rank)$/i',
            'status' => '/^(status|state|pipeline|stage)$/i',
        ];

        $mapping = [];
        foreach (self::FIELDS as $field) {
            $mapping[$field] = null;
            foreach ($headers as $header) {
                if (preg_match($patterns[$field], $header) === 1) {
                    $mapping[$field] = $header;
                    break;
                }
            }
        }

        return $mapping;
    }

    /**
     * Build a per-field row preview for the UI (up to N rows mapped to canonical fields).
     *
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     * @param  array<string, ?string>  $mapping
     * @return list<array<string, ?string>>
     */
    public function preview(array $headers, array $rows, array $mapping, int $limit = 10): array
    {
        $headerIndex = array_flip($headers);
        $out = [];

        foreach (array_slice($rows, 0, $limit) as $row) {
            $mapped = [];
            foreach (self::FIELDS as $field) {
                $header = $mapping[$field] ?? null;
                $index = $header !== null && isset($headerIndex[$header]) ? $headerIndex[$header] : null;
                $mapped[$field] = $index !== null && isset($row[$index]) ? ($row[$index] !== '' ? $row[$index] : null) : null;
            }
            $out[] = $mapped;
        }

        return $out;
    }

    /**
     * Run the import: insert one Company per row whose derived domain isn't
     * already present for this tenant. Emits a COMPANY_ADDED event per insert.
     *
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     * @param  array<string, ?string>  $mapping
     * @return array{created: int, skipped: int, failed: int, errors: list<array{row: int, message: string}>}
     */
    public function run(array $headers, array $rows, array $mapping): array
    {
        foreach (self::REQUIRED_FIELDS as $required) {
            if (($mapping[$required] ?? null) === null) {
                throw ValidationException::withMessages([
                    "mapping.{$required}" => "The {$required} column must be mapped before running the import.",
                ]);
            }
        }

        $headerIndex = array_flip($headers);
        $existingDomains = Company::query()->whereNotNull('domain')->pluck('domain')->all();
        $existingByDomain = array_fill_keys($existingDomains, true);

        $created = 0;
        $skipped = 0;
        $failed = 0;
        $errors = [];

        $now = CarbonImmutable::now();

        DB::transaction(function () use (
            $rows,
            $mapping,
            $headerIndex,
            &$existingByDomain,
            &$created,
            &$skipped,
            &$failed,
            &$errors,
            $now,
        ): void {
            foreach ($rows as $rowNumber => $row) {
                $rowOneIndexed = $rowNumber + 2; // +1 for zero-index, +1 for header row

                $values = [];
                foreach (self::FIELDS as $field) {
                    $header = $mapping[$field] ?? null;
                    $index = $header !== null && isset($headerIndex[$header]) ? $headerIndex[$header] : null;
                    $raw = $index !== null && isset($row[$index]) ? $row[$index] : '';
                    $values[$field] = $raw === '' ? null : $raw;
                }

                if (! is_string($values['name']) || $values['name'] === '') {
                    $failed++;
                    $errors[] = ['row' => $rowOneIndexed, 'message' => 'Missing name.'];

                    continue;
                }

                $domain = $this->resolveDomain($values);
                if ($domain !== null && isset($existingByDomain[$domain])) {
                    $skipped++;

                    continue;
                }

                $values['domain'] = $domain;
                $values['tier'] = $this->normalizeTier($values['tier']);
                $values['status'] = $this->normalizeStatus($values['status']);

                $detection = $this->atsDetector->detect($values['careers_url'] ?? $values['website'] ?? null);
                if ($detection !== null) {
                    $values['ats_type'] = $detection['ats_type'];
                    $values['ats_slug'] = $detection['ats_slug'];
                }

                $company = Company::query()->create($values);

                Event::query()->create([
                    'company_id' => $company->id,
                    'role_id' => null,
                    'type' => EventType::COMPANY_ADDED->value,
                    'title' => "Imported {$company->name}",
                    'body' => null,
                    'occurred_at' => $now,
                ]);

                if ($domain !== null) {
                    $existingByDomain[$domain] = true;
                }

                $created++;
            }
        });

        return [
            'created' => $created,
            'skipped' => $skipped,
            'failed' => $failed,
            'errors' => array_slice($errors, 0, 20),
        ];
    }

    /**
     * @param  array<string, ?string>  $values
     */
    private function resolveDomain(array $values): ?string
    {
        if (isset($values['domain']) && is_string($values['domain']) && $values['domain'] !== '') {
            return mb_strtolower($values['domain']);
        }

        $website = $values['website'] ?? null;
        if (! is_string($website) || $website === '') {
            return null;
        }

        if (! str_contains($website, '://')) {
            $website = 'https://'.$website;
        }

        $host = parse_url($website, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return null;
        }

        return mb_strtolower(preg_replace('/^www\./i', '', $host) ?? $host);
    }

    private function normalizeTier(?string $raw): string
    {
        $value = strtoupper(trim((string) $raw));

        return in_array($value, ['A', 'B', 'C', 'GRAVEYARD'], true) ? ($value === 'GRAVEYARD' ? 'graveyard' : $value) : 'C';
    }

    private function normalizeStatus(?string $raw): string
    {
        $allowed = ['researching', 'watching', 'applied', 'in_process', 'rejected', 'offer', 'passed'];
        $value = strtolower(str_replace([' ', '-'], '_', trim((string) $raw)));

        return in_array($value, $allowed, true) ? $value : 'researching';
    }
}
