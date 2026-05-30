<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Enums\ExportFormat;
use App\Enums\ExportJobStatus;
use App\Jobs\BuildDataExportJob;
use App\Models\ExportJob;
use App\Tenancy\TenantContext;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Creates a PENDING ExportJob for the current tenant and queues the build.
 *
 * All three formats (SQL, JSON, CSV) are always requested; the bundle is
 * produced by BuildDataExportJob (sync in tests, queued in production via the
 * ShouldQueue contract).
 */
final readonly class RequestExportController
{
    public function __construct(
        private Dispatcher $bus,
        private TenantContext $tenants,
        private ConfigRepository $config,
    ) {}

    public function __invoke(Request $request): RedirectResponse
    {
        $tenant = $this->tenants->currentOrFail();

        $export = ExportJob::query()->create([
            'user_id' => (int) $request->user()->getKey(),
            'status' => ExportJobStatus::PENDING->value,
            'formats' => [
                ExportFormat::SQL->value,
                ExportFormat::JSON->value,
                ExportFormat::CSV->value,
            ],
            'disk' => (string) $this->config->get('export.disk', 'local'),
        ]);

        $this->bus->dispatch(new BuildDataExportJob(
            (int) $export->getKey(),
            (int) $tenant->id,
        ));

        return redirect()
            ->route('settings.data')
            ->with('success', 'Your data export is being prepared. It will appear below when ready.');
    }
}
