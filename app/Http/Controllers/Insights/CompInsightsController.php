<?php

declare(strict_types=1);

namespace App\Http\Controllers\Insights;

use App\Tenancy\TenantContext;
use Illuminate\Database\ConnectionInterface;
use Inertia\Inertia;
use Inertia\Response;

final readonly class CompInsightsController
{
    public function __construct(
        private ConnectionInterface $db,
        private TenantContext $tenantContext,
    ) {}

    public function __invoke(): Response
    {
        $tenantId = $this->tenantContext->currentOrFail()->id;

        $excludedStatuses = ['dismissed', 'duplicate', 'auto_dismissed'];

        // Base CTE: per-listing midpoint USD compensation.
        // PERCENTILE_CONT requires PG; the test config uses pgsql.
        $baseFilters = [
            'tenant_id = ?',
            'has_comp = true',
            'parsed_comp_min_usd IS NOT NULL',
            'parsed_comp_max_usd IS NOT NULL',
            'status NOT IN (?, ?, ?)',
        ];
        $baseWhere = implode(' AND ', $baseFilters);
        $bindings = [$tenantId, ...$excludedStatuses];

        // Role family: composes parsed_seniority x discipline (the columns
        // DisciplineExtractor / SeniorityExtractor already populate), e.g.
        // "Senior Backend", "Staff Fullstack", or a discipline-only label
        // when seniority is missing. Rows parsed before the `discipline`
        // column existed fall back to the original title-regex heuristic
        // until reparse:reparse-all backfills them.
        $byRoleFamilyRows = $this->db->select(
            <<<SQL
            WITH base AS (
                SELECT
                    parsed_role_title,
                    parsed_seniority,
                    discipline,
                    (parsed_comp_min_usd + parsed_comp_max_usd) / 2.0 AS midpoint
                FROM aggregator_listings
                WHERE {$baseWhere}
            ),
            labelled AS (
                SELECT
                    CASE
                        WHEN discipline IS NOT NULL THEN
                            TRIM(
                                (CASE parsed_seniority
                                    WHEN 'intern' THEN 'Intern '
                                    WHEN 'junior' THEN 'Junior '
                                    WHEN 'mid' THEN 'Mid '
                                    WHEN 'senior' THEN 'Senior '
                                    WHEN 'staff' THEN 'Staff '
                                    WHEN 'principal' THEN 'Principal '
                                    WHEN 'lead' THEN 'Lead '
                                    WHEN 'director' THEN 'Director '
                                    ELSE ''
                                END)
                                ||
                                (CASE discipline
                                    WHEN 'backend' THEN 'Backend'
                                    WHEN 'frontend' THEN 'Frontend'
                                    WHEN 'fullstack' THEN 'Fullstack'
                                    WHEN 'mobile' THEN 'Mobile'
                                    WHEN 'devops' THEN 'DevOps'
                                    WHEN 'data' THEN 'Data'
                                    WHEN 'ml' THEN 'ML'
                                    WHEN 'security' THEN 'Security'
                                    WHEN 'qa' THEN 'QA'
                                    WHEN 'design' THEN 'Design'
                                    WHEN 'engineering' THEN 'Engineering'
                                    ELSE INITCAP(discipline)
                                END)
                            )
                        WHEN parsed_role_title ~* '(staff|principal).*(full[- ]?stack)' THEN 'Staff Fullstack'
                        WHEN parsed_role_title ~* '(staff|principal).*(back[- ]?end)' THEN 'Staff Backend'
                        WHEN parsed_role_title ~* '(senior|sr\.?).*(back[- ]?end)' THEN 'Senior Backend'
                        WHEN parsed_role_title ~* '(senior|sr\.?).*(front[- ]?end)' THEN 'Senior Frontend'
                        WHEN parsed_role_title ~* '(senior|sr\.?).*(full[- ]?stack)' THEN 'Senior Fullstack'
                        WHEN parsed_role_title ~* '(engineering[ -]?manager|^em\\b|head of engineering)' THEN 'Engineering Manager'
                        ELSE 'Unclassified'
                    END AS label,
                    midpoint
                FROM base
            )
            SELECT
                label,
                COUNT(*)::int AS sample_count,
                PERCENTILE_CONT(0.25) WITHIN GROUP (ORDER BY midpoint) AS p25,
                PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY midpoint) AS median,
                PERCENTILE_CONT(0.75) WITHIN GROUP (ORDER BY midpoint) AS p75
            FROM labelled
            GROUP BY label
            ORDER BY sample_count DESC, label
            SQL,
            $bindings,
        );

        // Geography: location_bucket is authoritative and exactly one of
        // worldwide|emea|us|apac|americas. Rows parsed before RegionExtractor
        // covered their source (location_bucket IS NULL) fall back to the
        // coarser parsed_remote_type heuristic until reparse:reparse-all
        // backfills them.
        $byGeographyRows = $this->db->select(
            <<<SQL
            WITH base AS (
                SELECT
                    parsed_remote_type,
                    location_bucket,
                    (parsed_comp_min_usd + parsed_comp_max_usd) / 2.0 AS midpoint
                FROM aggregator_listings
                WHERE {$baseWhere}
            ),
            labelled AS (
                SELECT
                    CASE
                        WHEN location_bucket = 'us' THEN 'US'
                        WHEN location_bucket = 'emea' THEN 'EMEA'
                        WHEN location_bucket = 'apac' THEN 'APAC'
                        WHEN location_bucket = 'americas' THEN 'Americas'
                        WHEN location_bucket = 'worldwide' THEN 'Worldwide'
                        WHEN location_bucket IS NULL AND parsed_remote_type ILIKE '%us%' THEN 'US'
                        WHEN location_bucket IS NULL AND parsed_remote_type ILIKE '%eu%' THEN 'EMEA'
                        WHEN location_bucket IS NULL AND parsed_remote_type ILIKE '%remote%' THEN 'Worldwide'
                        ELSE 'Unclassified'
                    END AS label,
                    midpoint
                FROM base
            )
            SELECT
                label,
                COUNT(*)::int AS sample_count,
                PERCENTILE_CONT(0.25) WITHIN GROUP (ORDER BY midpoint) AS p25,
                PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY midpoint) AS median,
                PERCENTILE_CONT(0.75) WITHIN GROUP (ORDER BY midpoint) AS p75
            FROM labelled
            GROUP BY label
            ORDER BY sample_count DESC, label
            SQL,
            $bindings,
        );

        $byCompanyRows = $this->db->select(
            <<<'SQL'
            WITH base AS (
                SELECT
                    l.matched_company_id,
                    c.name AS company_name,
                    (l.parsed_comp_min_usd + l.parsed_comp_max_usd) / 2.0 AS midpoint
                FROM aggregator_listings l
                JOIN companies c ON c.id = l.matched_company_id
                WHERE l.tenant_id = ?
                  AND l.has_comp = true
                  AND l.parsed_comp_min_usd IS NOT NULL
                  AND l.parsed_comp_max_usd IS NOT NULL
                  AND l.status NOT IN (?, ?, ?)
                  AND l.matched_company_id IS NOT NULL
            )
            SELECT
                matched_company_id AS company_id,
                company_name,
                COUNT(*)::int AS sample_count,
                PERCENTILE_CONT(0.25) WITHIN GROUP (ORDER BY midpoint) AS p25,
                PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY midpoint) AS median,
                PERCENTILE_CONT(0.75) WITHIN GROUP (ORDER BY midpoint) AS p75
            FROM base
            GROUP BY matched_company_id, company_name
            HAVING COUNT(*) >= 3
            ORDER BY sample_count DESC, company_name
            LIMIT 50
            SQL,
            [$tenantId, ...$excludedStatuses],
        );

        return Inertia::render('Insights/Comp', [
            'byRoleFamily' => array_map(fn ($r) => [
                'label' => (string) $r->label,
                'sample_count' => (int) $r->sample_count,
                'p25' => $r->p25 === null ? null : (int) round((float) $r->p25),
                'median' => $r->median === null ? null : (int) round((float) $r->median),
                'p75' => $r->p75 === null ? null : (int) round((float) $r->p75),
            ], $byRoleFamilyRows),
            'byGeography' => array_map(fn ($r) => [
                'label' => (string) $r->label,
                'sample_count' => (int) $r->sample_count,
                'p25' => $r->p25 === null ? null : (int) round((float) $r->p25),
                'median' => $r->median === null ? null : (int) round((float) $r->median),
                'p75' => $r->p75 === null ? null : (int) round((float) $r->p75),
            ], $byGeographyRows),
            'byCompany' => array_map(fn ($r) => [
                'company_id' => (int) $r->company_id,
                'company_name' => (string) $r->company_name,
                'sample_count' => (int) $r->sample_count,
                'p25' => $r->p25 === null ? null : (int) round((float) $r->p25),
                'median' => $r->median === null ? null : (int) round((float) $r->median),
                'p75' => $r->p75 === null ? null : (int) round((float) $r->p75),
            ], $byCompanyRows),
        ]);
    }
}
