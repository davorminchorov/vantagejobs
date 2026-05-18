<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\EventType;
use App\Models\Company;
use App\Models\Event;
use App\Scrapers\Aggregator\CompanyKeyNormalizer;
use App\Signals\Funding\YcOssClient;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;

/**
 * Sync tracked companies against the yc-oss YC mirror to detect status
 * changes (Active → Acquired / Inactive / Public). yc-oss does not
 * carry funding amounts, but the status enum is a high-signal proxy
 * for "company materially changed" — Acquired is a funding-adjacent
 * event, Inactive is graveyard-candidacy, Public is a milestone.
 *
 * For matched companies we record the last-seen yc-oss status in
 * `yc_oss_status` (jsonb under the existing parsed columns — we don't
 * add a column for it; the per-tenant Setting table would be overkill).
 * Status change writes a `funding` Event with subtype in the body.
 *
 * Matching: by `normalized_company_key` (CompanyKeyNormalizer) against
 * the yc-oss `name`. Domain-based matching could supplement but is
 * out of scope for this pass.
 */
final class SignalsFundingYcOssCommand extends Command
{
    protected $signature = 'signals:funding:yc-oss
        {--tenant= : Tenant slug}
        {--endpoint= : Override the yc-oss endpoint URL}';

    protected $description = 'Detect YC company status changes against the yc-oss mirror and emit funding/status Events.';

    public function __construct(
        private readonly YcOssClient $client,
        private readonly CompanyKeyNormalizer $normalizer,
        private readonly ConnectionInterface $db,
    ) {
        parent::__construct();
    }

    public function handle(TenantContext $context): int
    {
        $tenant = $this->resolveTenant();
        if (! $tenant instanceof Tenant) {
            return self::FAILURE;
        }
        $context->setCurrent($tenant);

        $startedAt = CarbonImmutable::now();

        try {
            $endpoint = $this->option('endpoint');
            $records = is_string($endpoint) && $endpoint !== ''
                ? $this->client->fetchAll($endpoint)
                : $this->client->fetchAll();
        } catch (\Throwable $e) {
            $this->error('yc-oss fetch failed: '.$e->getMessage());

            return self::FAILURE;
        }

        // Index yc-oss records by normalized name for O(1) match.
        $byKey = [];
        foreach ($records as $r) {
            $name = isset($r['name']) && is_string($r['name']) ? $r['name'] : null;
            if ($name === null) {
                continue;
            }
            $key = $this->normalizer->normalize($name);
            if ($key !== null && $key !== '') {
                $byKey[$key] = $r;
            }
        }

        $companies = Company::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get(['id', 'name', 'tier']);

        $matched = 0;
        $eventsEmitted = 0;
        foreach ($companies as $company) {
            $key = $this->normalizer->normalize($company->name);
            if ($key === null || $key === '') {
                continue;
            }
            $record = $byKey[$key] ?? null;
            if ($record === null) {
                continue;
            }
            $matched++;

            $status = isset($record['status']) && is_string($record['status']) ? $record['status'] : null;
            if ($status === null) {
                continue;
            }

            $lastSeen = $this->lastSeenStatus($company->id);
            if ($lastSeen === $status) {
                continue;
            }

            Event::query()->create([
                'company_id' => $company->id,
                'role_id' => null,
                'type' => EventType::FUNDING->value,
                'title' => "YC status: {$status}".($lastSeen !== null ? " (was {$lastSeen})" : ''),
                'body' => (string) json_encode([
                    'source' => 'yc_oss',
                    'yc_status' => $status,
                    'previous_status' => $lastSeen,
                    'batch' => $record['batch'] ?? null,
                    'team_size' => $record['team_size'] ?? null,
                    'url' => $record['url'] ?? null,
                ]),
                'occurred_at' => CarbonImmutable::now(),
            ]);
            $eventsEmitted++;
        }

        $finishedAt = CarbonImmutable::now();
        $this->line((string) json_encode([
            'command' => 'signals:funding:yc-oss',
            'tenant' => $tenant->slug,
            'yc_records' => count($byKey),
            'companies' => $companies->count(),
            'matched' => $matched,
            'events_emitted' => $eventsEmitted,
            'duration_ms' => $finishedAt->diffInMilliseconds($startedAt, true),
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /**
     * Last yc-oss status recorded for a company, by digging into the
     * most recent funding Event with source=yc_oss.
     */
    private function lastSeenStatus(int $companyId): ?string
    {
        $row = $this->db->table('events')
            ->where('company_id', $companyId)
            ->where('type', EventType::FUNDING->value)
            ->where('body', 'like', '%"source":"yc_oss"%')
            ->orderByDesc('occurred_at')
            ->limit(1)
            ->value('body');

        if (! is_string($row) || $row === '') {
            return null;
        }
        $decoded = json_decode($row, true);
        if (! is_array($decoded)) {
            return null;
        }
        $status = $decoded['yc_status'] ?? null;

        return is_string($status) ? $status : null;
    }

    private function resolveTenant(): ?Tenant
    {
        $slug = $this->option('tenant');
        if (! is_string($slug) || $slug === '') {
            $this->error('The --tenant=<slug> option is required.');

            return null;
        }
        /** @var Tenant|null $tenant */
        $tenant = Tenant::query()->where('slug', $slug)->first();
        if (! $tenant instanceof Tenant) {
            $this->error("Tenant [{$slug}] not found.");

            return null;
        }

        return $tenant;
    }
}
