<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\EventType;
use App\Models\Company;
use App\Models\Event;
use App\Signals\Ats\AtsRedetector;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Sweep companies flagged ats_status='needs_redetection' and try to find
 * a new ATS board for them (PRD §11.2). On success we update ats_type +
 * ats_slug, reset the failure counters, and emit an `ats_redetected`
 * Event so the company timeline records the switch. On failure we bump
 * `ats_redetect_attempts`; after the third miss we lock the company at
 * `ats_status='redetect_failed'` and emit an `ats_redetect_failed` Event
 * so /admin/runs surfaces it.
 */
final class SignalsAtsRedetectCommand extends Command
{
    private const REDETECT_ATTEMPT_LIMIT = 3;

    protected $signature = 'signals:ats:redetect
        {--tenant= : Tenant slug}
        {--company= : Only redetect a single company (id)}';

    protected $description = 'Re-run ATS auto-detection for companies whose existing scraper returned 404 too many times.';

    public function __construct(private readonly AtsRedetector $redetector)
    {
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

        $query = Company::query()
            ->where('ats_status', 'needs_redetection')
            ->orderBy('id');

        $companyFilter = $this->option('company');
        if (is_string($companyFilter) && $companyFilter !== '') {
            $query->where('id', (int) $companyFilter);
        }

        /** @var iterable<int, Company> $companies */
        $companies = $query->get();

        $checked = 0;
        $succeeded = 0;
        $failed = 0;
        $exhausted = 0;

        foreach ($companies as $company) {
            $checked++;

            $detected = $this->redetector->redetect($company);

            if ($detected !== null) {
                $this->applySuccess($company, $detected);
                $succeeded++;

                continue;
            }

            $failed++;

            $attempts = (int) $company->ats_redetect_attempts + 1;
            $company->ats_redetect_attempts = $attempts;

            if ($attempts >= self::REDETECT_ATTEMPT_LIMIT) {
                $company->ats_status = 'redetect_failed';
                $company->save();

                Event::query()->create([
                    'company_id' => $company->id,
                    'role_id' => null,
                    'type' => EventType::ATS_REDETECT_FAILED->value,
                    'title' => 'ATS redetection failed',
                    'body' => (string) json_encode([
                        'attempts' => $attempts,
                        'previous_ats_type' => $company->ats_type,
                        'previous_ats_slug' => $company->ats_slug,
                        'careers_url' => $company->careers_url,
                    ], JSON_THROW_ON_ERROR),
                    'occurred_at' => CarbonImmutable::now(),
                ]);

                $exhausted++;

                continue;
            }

            $company->save();
        }

        $finishedAt = CarbonImmutable::now();

        $this->line((string) json_encode([
            'command' => 'signals:ats:redetect',
            'tenant' => $tenant->slug,
            'companies_checked' => $checked,
            'succeeded' => $succeeded,
            'failed' => $failed,
            'exhausted' => $exhausted,
            'duration_ms' => $finishedAt->diffInMilliseconds($startedAt, true),
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /**
     * @param  array{ats_type: string, ats_slug: string}  $detected
     */
    private function applySuccess(Company $company, array $detected): void
    {
        $oldAtsType = $company->ats_type;
        $oldAtsSlug = $company->ats_slug;

        $company->ats_type = $detected['ats_type'];
        $company->ats_slug = $detected['ats_slug'];
        $company->ats_consecutive_404s = 0;
        $company->ats_redetect_attempts = 0;
        $company->ats_status = 'ok';
        // Clear conditional-GET caches so the next scrape starts fresh.
        $company->etag = null;
        $company->last_modified = null;
        $company->save();

        Event::query()->create([
            'company_id' => $company->id,
            'role_id' => null,
            'type' => EventType::ATS_REDETECTED->value,
            'title' => 'ATS redetected',
            'body' => (string) json_encode([
                'old' => ['ats_type' => $oldAtsType, 'ats_slug' => $oldAtsSlug],
                'new' => $detected,
            ], JSON_THROW_ON_ERROR),
            'occurred_at' => CarbonImmutable::now(),
        ]);
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
