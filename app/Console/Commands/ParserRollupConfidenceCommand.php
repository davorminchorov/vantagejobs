<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AggregatorSource;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;

/**
 * Daily roll-up of per-(tenant, source, field, day) parser confidence stats.
 *
 * Reads from `aggregator_listings.parsed` (jsonb) + `parse_corrections` and
 * upserts the windowed results into `parser_confidence_daily`. The
 * /admin/parser dashboard reads only from the roll-up so request-time cost
 * is bounded.
 *
 * Why percentiles rather than mean: live confidence distributions are
 * heavily bimodal (most slots are either ~0 / MISSING or ~0.9 / matched);
 * an average would always read "0.4" and tell you nothing. p10 surfaces
 * the worst tail, p50 the typical outcome, p90 the ceiling.
 *
 * Idempotent: re-running the same window produces the same DB state via
 * a four-column unique index (tenant_id, source_id, field, day).
 */
final class ParserRollupConfidenceCommand extends Command
{
    /**
     * Canonical ParsedListing slot keys (see ParsedListing class docblock).
     *
     * Every slot is its own jsonb object with its own `confidence` value.
     * The brief flagged `remote` and `compensation` as compound — they ARE
     * compound in value (e.g. `compensation.value` is `{min, max, currency,
     * interval}`) but their confidence is on the outer slot. We aggregate
     * over the outer confidence here. Children aren't separate slots.
     */
    public const array CANONICAL_FIELDS = [
        'company_name',
        'role_title',
        'discipline',
        'seniority',
        'location',
        'region',
        'remote',
        'compensation',
        'stack_tags',
        'employment_type',
        'visa_sponsorship',
        'apply_method',
    ];

    protected $signature = 'parser:rollup-confidence
        {--days=30 : Number of UTC days backward from today to recompute}
        {--source=all : Aggregator source type to recompute (defaults to all)}';

    protected $description = 'Recompute parser_confidence_daily for the recent window.';

    public function __construct(private readonly ConnectionInterface $db)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $startedAt = CarbonImmutable::now();
        $days = (int) $this->option('days');
        if ($days < 1) {
            $days = 1;
        }
        $sourceFilter = (string) $this->option('source');

        $sources = AggregatorSource::query()
            ->when($sourceFilter !== 'all', fn ($q) => $q->where('type', $sourceFilter))
            ->get();

        // UTC day boundaries. The window is [today-days+1, today] inclusive
        // so --days=1 means just today.
        $today = $startedAt->utc()->startOfDay();
        $windowStart = $today->subDays($days - 1);

        $rowsUpserted = 0;
        $sourcesProcessed = 0;
        $fieldsProcessed = 0;

        foreach ($sources as $source) {
            $sourcesProcessed++;

            foreach (self::CANONICAL_FIELDS as $field) {
                $rowsUpserted += $this->rollupForSourceField(
                    tenantId: (int) $source->tenant_id,
                    sourceId: (int) $source->id,
                    field: $field,
                    windowStart: $windowStart,
                    windowEnd: $today->addDay(),
                );
                $fieldsProcessed++;
            }
        }

        $finishedAt = CarbonImmutable::now();

        $this->line((string) json_encode([
            'command' => 'parser:rollup-confidence',
            'ran_at' => $startedAt->toIso8601String(),
            'window_days' => $days,
            'sources_processed' => $sourcesProcessed,
            'fields_processed' => $fieldsProcessed,
            'rows_upserted' => $rowsUpserted,
            'duration_ms' => $finishedAt->diffInMilliseconds($startedAt, true),
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /**
     * Aggregate one (source, field) over the window and upsert one row per
     * UTC day. Returns the number of rows written.
     */
    private function rollupForSourceField(
        int $tenantId,
        int $sourceId,
        string $field,
        CarbonImmutable $windowStart,
        CarbonImmutable $windowEnd,
    ): int {
        // Compute daily confidence percentiles + counts for this slot.
        // jsonb_exists(parsed, $field) keeps us off rows missing the slot
        // entirely (cheaper than `? operator` which Laravel mistakes for a
        // binding placeholder).
        $confidenceRows = $this->db->select(<<<'SQL'
            SELECT (fetched_at AT TIME ZONE 'UTC')::date AS day,
                   COUNT(*) FILTER (WHERE (parsed -> ? ->> 'confidence')::float > 0)             AS n,
                   COUNT(*) FILTER (WHERE COALESCE((parsed -> ? ->> 'confidence')::float, 0) = 0) AS n_missing,
                   PERCENTILE_CONT(0.1) WITHIN GROUP (ORDER BY (parsed -> ? ->> 'confidence')::float) AS p10,
                   PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY (parsed -> ? ->> 'confidence')::float) AS p50,
                   PERCENTILE_CONT(0.9) WITHIN GROUP (ORDER BY (parsed -> ? ->> 'confidence')::float) AS p90
              FROM aggregator_listings
             WHERE tenant_id = ?
               AND source_id = ?
               AND parsed IS NOT NULL
               AND jsonb_exists(parsed, ?)
               AND fetched_at >= ?
               AND fetched_at <  ?
             GROUP BY 1
        SQL,
            [$field, $field, $field, $field, $field, $tenantId, $sourceId, $field,
                $windowStart->toIso8601String(), $windowEnd->toIso8601String()]
        );

        // Correction counts for the same (source, field, day) buckets. We
        // join on `parse_corrections.created_at` to keep the day attribution
        // consistent with how the dashboard reads "recent corrections".
        $correctionRows = $this->db->select(<<<'SQL'
            SELECT (pc.created_at AT TIME ZONE 'UTC')::date AS day,
                   COUNT(*) AS n_corrections
              FROM parse_corrections pc
              JOIN aggregator_listings l ON l.id = pc.listing_id
             WHERE pc.tenant_id = ?
               AND l.source_id = ?
               AND pc.field    = ?
               AND pc.created_at >= ?
               AND pc.created_at <  ?
             GROUP BY 1
        SQL,
            [$tenantId, $sourceId, $field,
                $windowStart->toIso8601String(), $windowEnd->toIso8601String()]
        );

        $correctionByDay = [];
        foreach ($correctionRows as $cr) {
            $correctionByDay[$cr->day] = (int) $cr->n_corrections;
        }

        $upserts = [];
        $touchedDays = [];
        $now = CarbonImmutable::now();

        foreach ($confidenceRows as $row) {
            $touchedDays[$row->day] = true;
            $upserts[] = [
                'tenant_id' => $tenantId,
                'source_id' => $sourceId,
                'field' => $field,
                'day' => $row->day,
                'n' => (int) $row->n,
                'n_missing' => (int) $row->n_missing,
                'p10' => $row->p10 !== null ? round((float) $row->p10, 4) : null,
                'p50' => $row->p50 !== null ? round((float) $row->p50, 4) : null,
                'p90' => $row->p90 !== null ? round((float) $row->p90, 4) : null,
                'n_corrections' => $correctionByDay[$row->day] ?? 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // Days with corrections but no listings (rare — happens if a user
        // corrected a stale listing today). Still want them in the rollup
        // so the corrections leaderboard is honest.
        foreach ($correctionByDay as $day => $count) {
            if (isset($touchedDays[$day])) {
                continue;
            }
            $upserts[] = [
                'tenant_id' => $tenantId,
                'source_id' => $sourceId,
                'field' => $field,
                'day' => $day,
                'n' => 0,
                'n_missing' => 0,
                'p10' => null,
                'p50' => null,
                'p90' => null,
                'n_corrections' => $count,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($upserts === []) {
            return 0;
        }

        $this->db->table('parser_confidence_daily')->upsert(
            $upserts,
            ['tenant_id', 'source_id', 'field', 'day'],
            ['n', 'n_missing', 'p10', 'p50', 'p90', 'n_corrections', 'updated_at'],
        );

        return count($upserts);
    }
}
