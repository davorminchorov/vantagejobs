<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ExportJobStatus;
use App\Exports\DataExportBuilder;
use App\Models\ExportJob;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Builds the export bundle for a single ExportJob on the queue.
 *
 * The job carries only the export job id and its tenant id; it re-establishes
 * the tenant context on the worker so DataExportBuilder's tenant-scoped reads
 * resolve correctly off a request cycle. A failed build leaves the ExportJob
 * row stamped FAILED with the error message (handled inside the builder), and
 * the queue's own failure handling records the exception.
 */
final class BuildDataExportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        private readonly int $exportJobId,
        private readonly int $tenantId,
    ) {}

    public function handle(TenantContext $context, DataExportBuilder $builder): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        if (! $tenant instanceof Tenant) {
            return;
        }

        $context->withTenant($tenant, function () use ($builder): void {
            $job = ExportJob::query()->find($this->exportJobId);

            if (! $job instanceof ExportJob) {
                return;
            }

            $builder->build($job);
        });
    }

    public function failed(Throwable $exception): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        if (! $tenant instanceof Tenant) {
            return;
        }

        app(TenantContext::class)->withTenant($tenant, function () use ($exception): void {
            $job = ExportJob::query()->find($this->exportJobId);

            if (! $job instanceof ExportJob || $job->status === ExportJobStatus::COMPLETED) {
                return;
            }

            $job->forceFill([
                'status' => ExportJobStatus::FAILED->value,
                'error' => $exception->getMessage(),
            ])->save();
        });
    }
}
