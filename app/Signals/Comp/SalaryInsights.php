<?php

declare(strict_types=1);

namespace App\Signals\Comp;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * Salary percentiles from aggregator listings that post a pay range.
 *
 * A sample is one listing's range midpoint in USD. Only annual salaries
 * between MIN_ANNUAL_USD and MAX_ANNUAL_USD count: hourly rates and
 * out-of-range values (mostly parse errors) are counted separately so the
 * page can say how many were left out. Dismissed, duplicate and
 * auto-dismissed listings never count; expired and overflow ones do, since
 * the posted range is still a real data point.
 *
 * Seniority comes from the parser as either an extractor value (`mid`,
 * `junior`) or a source's own label (`mid-level`, `entry-level`,
 * `executive`), so LEVEL_ALIASES folds both into one ladder.
 */
final readonly class SalaryInsights
{
    public const int MIN_ANNUAL_USD = 15_000;

    public const int MAX_ANNUAL_USD = 1_000_000;

    public const int RECENT_LIMIT = 25;

    /** @var list<string> */
    private const array EXCLUDED_STATUSES = ['dismissed', 'duplicate', 'auto_dismissed'];

    /** @var array<string, string> Ladder order, lowest first. */
    public const array LEVELS = [
        'intern' => 'Intern',
        'junior' => 'Junior',
        'mid' => 'Mid',
        'senior' => 'Senior',
        'lead' => 'Lead',
        'staff' => 'Staff',
        'principal' => 'Principal',
        'manager' => 'Manager',
        'director' => 'Director and above',
        'unspecified' => 'Level not stated',
    ];

    /** @var array<string, string> Raw parsed_seniority value => LEVELS key. */
    private const array LEVEL_ALIASES = [
        'intern' => 'intern',
        'junior' => 'junior',
        'entry-level' => 'junior',
        'mid' => 'mid',
        'mid-level' => 'mid',
        'midweight' => 'mid',
        'senior' => 'senior',
        'lead' => 'lead',
        'staff' => 'staff',
        'principal' => 'principal',
        'manager' => 'manager',
        'director' => 'director',
        'executive' => 'director',
    ];

    /** @var array<string, string> */
    public const array DISCIPLINES = [
        'backend' => 'Backend',
        'frontend' => 'Frontend',
        'fullstack' => 'Fullstack',
        'mobile' => 'Mobile',
        'devops' => 'DevOps',
        'data' => 'Data',
        'ml' => 'ML and AI',
        'security' => 'Security',
        'qa' => 'QA',
        'design' => 'Design',
        'engineering' => 'General engineering',
    ];

    /** @var array<string, string> */
    public const array REGIONS = [
        'us' => 'US',
        'emea' => 'EMEA',
        'americas' => 'Americas (non-US)',
        'apac' => 'APAC',
        'worldwide' => 'Worldwide',
    ];

    public const string REGION_UNKNOWN_LABEL = 'Region not stated';

    public function __construct(private ConnectionInterface $db) {}

    /**
     * @return array{
     *     summary: array{samples: int, p25: int|null, median: int|null, p75: int|null, hourly_excluded: int, out_of_range_excluded: int},
     *     by_level: list<array{key: string, label: string, samples: int, p25: int|null, median: int|null, p75: int|null}>,
     *     by_region: list<array{key: string, label: string, samples: int, p25: int|null, median: int|null, p75: int|null}>,
     *     recent: list<array<string, mixed>>,
     * }
     */
    public function explore(int $tenantId, SalaryFilters $filters): array
    {
        return [
            'summary' => $this->summary($tenantId, $filters),
            'by_level' => $this->byLevel($tenantId, $filters),
            'by_region' => $this->byRegion($tenantId, $filters),
            'recent' => $this->recent($tenantId, $filters),
        ];
    }

    /**
     * @return array{samples: int, p25: int|null, median: int|null, p75: int|null, hourly_excluded: int, out_of_range_excluded: int}
     */
    private function summary(int $tenantId, SalaryFilters $filters): array
    {
        [$where, $bindings] = $this->scope($tenantId, $filters);
        $valid = $this->validSample();
        $interval = self::intervalSql();
        $midpoint = self::midpointSql();

        $row = $this->db->selectOne(
            <<<SQL
            SELECT
                COUNT(*) FILTER (WHERE {$valid})::int AS samples,
                PERCENTILE_CONT(0.25) WITHIN GROUP (ORDER BY {$midpoint}) FILTER (WHERE {$valid}) AS p25,
                PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY {$midpoint}) FILTER (WHERE {$valid}) AS median,
                PERCENTILE_CONT(0.75) WITHIN GROUP (ORDER BY {$midpoint}) FILTER (WHERE {$valid}) AS p75,
                COUNT(*) FILTER (WHERE {$interval} = 'hourly')::int AS hourly_excluded,
                COUNT(*) FILTER (WHERE {$interval} <> 'hourly' AND NOT ({$valid}))::int AS out_of_range_excluded
            FROM aggregator_listings
            WHERE {$where}
            SQL,
            $bindings,
        );

        return [
            'samples' => (int) $row->samples,
            'p25' => self::round($row->p25),
            'median' => self::round($row->median),
            'p75' => self::round($row->p75),
            'hourly_excluded' => (int) $row->hourly_excluded,
            'out_of_range_excluded' => (int) $row->out_of_range_excluded,
        ];
    }

    /**
     * Every rung of the ladder for the other filters, in ladder order.
     * Rungs with no samples are left out.
     *
     * @return list<array{key: string, label: string, samples: int, p25: int|null, median: int|null, p75: int|null}>
     */
    private function byLevel(int $tenantId, SalaryFilters $filters): array
    {
        $rows = $this->percentilesBy(self::levelSql(), $tenantId, new SalaryFilters(
            discipline: $filters->discipline,
            region: $filters->region,
            window: $filters->window,
        ));

        $out = [];
        foreach (self::LEVELS as $key => $label) {
            if (isset($rows[$key])) {
                $out[] = ['key' => $key, 'label' => $label, ...$rows[$key]];
            }
        }

        return $out;
    }

    /**
     * Every region for the other filters, largest sample first, with
     * listings that name no region last.
     *
     * @return list<array{key: string, label: string, samples: int, p25: int|null, median: int|null, p75: int|null}>
     */
    private function byRegion(int $tenantId, SalaryFilters $filters): array
    {
        $rows = $this->percentilesBy("COALESCE(location_bucket, 'unknown')", $tenantId, new SalaryFilters(
            discipline: $filters->discipline,
            level: $filters->level,
            window: $filters->window,
        ));

        $out = [];
        foreach ($rows as $key => $row) {
            $out[] = [
                'key' => (string) $key,
                'label' => self::REGIONS[$key] ?? self::REGION_UNKNOWN_LABEL,
                ...$row,
            ];
        }
        usort($out, static fn (array $a, array $b): int => [$a['key'] === 'unknown', -$a['samples'], $a['label']]
            <=> [$b['key'] === 'unknown', -$b['samples'], $b['label']]);

        return $out;
    }

    /**
     * @return array<string, array{samples: int, p25: int|null, median: int|null, p75: int|null}>
     */
    private function percentilesBy(string $groupSql, int $tenantId, SalaryFilters $filters): array
    {
        [$where, $bindings] = $this->scope($tenantId, $filters);
        $valid = $this->validSample();
        $midpoint = self::midpointSql();

        $rows = $this->db->select(
            <<<SQL
            SELECT
                {$groupSql} AS bucket,
                COUNT(*)::int AS samples,
                PERCENTILE_CONT(0.25) WITHIN GROUP (ORDER BY {$midpoint}) AS p25,
                PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY {$midpoint}) AS median,
                PERCENTILE_CONT(0.75) WITHIN GROUP (ORDER BY {$midpoint}) AS p75
            FROM aggregator_listings
            WHERE {$where} AND {$valid}
            GROUP BY 1
            SQL,
            $bindings,
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->bucket] = [
                'samples' => (int) $row->samples,
                'p25' => self::round($row->p25),
                'median' => self::round($row->median),
                'p75' => self::round($row->p75),
            ];
        }

        return $out;
    }

    /**
     * Newest samples behind the numbers.
     *
     * @return list<array<string, mixed>>
     */
    private function recent(int $tenantId, SalaryFilters $filters): array
    {
        [$where, $bindings] = $this->scope($tenantId, $filters, 'l.');
        $valid = $this->validSample('l.');
        $level = self::levelSql('l.');
        $limit = self::RECENT_LIMIT;

        $rows = $this->db->select(
            <<<SQL
            SELECT
                l.id,
                l.url,
                l.parsed_role_title,
                l.parsed_company_name,
                l.matched_company_id,
                c.name AS company_name,
                l.parsed_comp_min_usd,
                l.parsed_comp_max_usd,
                l.parsed->'compensation'->'value'->>'currency' AS currency,
                l.location_bucket,
                l.discipline,
                {$level} AS level,
                COALESCE(l.posted_at, l.created_at) AS seen_at
            FROM aggregator_listings l
            LEFT JOIN companies c ON c.id = l.matched_company_id
            WHERE {$where} AND {$valid}
            ORDER BY COALESCE(l.posted_at, l.created_at) DESC, l.id DESC
            LIMIT {$limit}
            SQL,
            $bindings,
        );

        return array_map(static fn (object $row): array => [
            'id' => (int) $row->id,
            'url' => $row->url === null ? null : (string) $row->url,
            'title' => (string) ($row->parsed_role_title ?? 'Untitled role'),
            'company_name' => (string) ($row->company_name ?? $row->parsed_company_name ?? 'Unknown company'),
            'company_id' => $row->matched_company_id === null ? null : (int) $row->matched_company_id,
            'min_usd' => (int) $row->parsed_comp_min_usd,
            'max_usd' => (int) $row->parsed_comp_max_usd,
            'currency' => $row->currency === null ? null : (string) $row->currency,
            'region' => $row->location_bucket === null ? null : (self::REGIONS[$row->location_bucket] ?? null),
            'discipline' => $row->discipline === null ? null : (self::DISCIPLINES[$row->discipline] ?? null),
            'level' => $row->level === 'unspecified' ? null : self::LEVELS[(string) $row->level],
            'seen_at' => CarbonImmutable::parse((string) $row->seen_at)->toIso8601String(),
        ], $rows);
    }

    /**
     * Listings in scope for the filters, before the annual/range check.
     *
     * @return array{string, list<mixed>}
     */
    private function scope(int $tenantId, SalaryFilters $filters, string $prefix = ''): array
    {
        $statusPlaceholders = implode(', ', array_fill(0, count(self::EXCLUDED_STATUSES), '?'));
        $clauses = [
            "{$prefix}tenant_id = ?",
            "{$prefix}has_comp = true",
            "{$prefix}parsed_comp_min_usd IS NOT NULL",
            "{$prefix}parsed_comp_max_usd IS NOT NULL",
            "{$prefix}status NOT IN ({$statusPlaceholders})",
        ];
        $bindings = [$tenantId, ...self::EXCLUDED_STATUSES];

        if ($filters->window > 0) {
            $clauses[] = "COALESCE({$prefix}posted_at, {$prefix}created_at) >= ?";
            $bindings[] = CarbonImmutable::now()->subDays($filters->window)->toDateTimeString();
        }

        if ($filters->discipline === SalaryFilters::DISCIPLINE_TECH) {
            $clauses[] = "{$prefix}discipline IS NOT NULL";
        } elseif ($filters->discipline !== SalaryFilters::DISCIPLINE_ALL) {
            $clauses[] = "{$prefix}discipline = ?";
            $bindings[] = $filters->discipline;
        }

        if ($filters->region !== SalaryFilters::ANY) {
            $clauses[] = "{$prefix}location_bucket = ?";
            $bindings[] = $filters->region;
        }

        if ($filters->level !== SalaryFilters::ANY) {
            $clauses[] = self::levelSql($prefix).' = ?';
            $bindings[] = $filters->level;
        }

        return [implode(' AND ', $clauses), $bindings];
    }

    private function validSample(string $prefix = ''): string
    {
        $interval = self::intervalSql($prefix);
        $midpoint = self::midpointSql($prefix);

        return sprintf(
            "%s <> 'hourly' AND %s BETWEEN %d AND %d",
            $interval,
            $midpoint,
            self::MIN_ANNUAL_USD,
            self::MAX_ANNUAL_USD,
        );
    }

    private static function intervalSql(string $prefix = ''): string
    {
        return "COALESCE({$prefix}parsed->'compensation'->'value'->>'interval', 'annual')";
    }

    private static function midpointSql(string $prefix = ''): string
    {
        return "(({$prefix}parsed_comp_min_usd::bigint + {$prefix}parsed_comp_max_usd) / 2.0)";
    }

    private static function levelSql(string $prefix = ''): string
    {
        $cases = '';
        foreach (self::LEVEL_ALIASES as $raw => $level) {
            $cases .= " WHEN '{$raw}' THEN '{$level}'";
        }

        return "(CASE LOWER({$prefix}parsed_seniority){$cases} ELSE 'unspecified' END)";
    }

    private static function round(mixed $value): ?int
    {
        return $value === null ? null : (int) round((float) $value);
    }
}
