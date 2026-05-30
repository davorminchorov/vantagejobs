<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Enums\ExportJobStatus;
use App\Models\ExportJob;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a completed export bundle from its disk while the 24h download
 * window is still open. The tenant global scope on ExportJob ensures one
 * tenant can never download another tenant's bundle; an expired or
 * not-yet-ready job 404s rather than leaking a path.
 */
final readonly class DownloadExportController
{
    public function __construct(private FilesystemFactory $filesystem) {}

    public function __invoke(Request $request, int $export): StreamedResponse
    {
        /** @var ExportJob|null $job */
        $job = ExportJob::query()->find($export);

        abort_if($job === null, 404);

        $expired = $job->status !== ExportJobStatus::COMPLETED
            || $job->path === null
            || $job->expires_at === null
            || $job->expires_at->isBefore(CarbonImmutable::now());

        abort_if($expired, 404);

        $disk = $this->filesystem->disk($this->diskName($job));

        abort_unless($disk->exists($job->path), 404);

        $filename = basename($job->path);

        return $disk->download($job->path, $filename, [
            'Content-Type' => 'application/zip',
        ]);
    }

    private function diskName(ExportJob $job): string
    {
        $disk = $job->disk;

        return is_string($disk) && $disk !== '' ? $disk : 'local';
    }
}
