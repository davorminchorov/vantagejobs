<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Runs;

use App\Models\ScrapeRunArtifact;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * GET /admin/runs/artifacts/{artifact}/download
 *
 * Streams a retained scrape_run_artifacts row's gzipped body back to the
 * caller for inspection. Two paths:
 *
 *   - If the client advertises gzip via Accept-Encoding, the gzipped body is
 *     streamed verbatim with Content-Encoding: gzip. No CPU cost to inflate.
 *   - Otherwise the body is inflated on the fly and streamed as the inner
 *     format (json / xml / html / bin).
 *
 * Returns 404 when:
 *   - the row's retained_until is in the past (purge has happened or is due),
 *   - the on-disk file is missing (orphan row from a half-failed write).
 */
final readonly class DownloadArtifactController
{
    public function __construct(
        private FilesystemFactory $filesystem,
    ) {}

    public function __invoke(Request $request, int $artifact): StreamedResponse
    {
        $row = ScrapeRunArtifact::query()->find($artifact);

        if ($row === null) {
            throw new NotFoundHttpException('Artifact not found.');
        }

        if ($row->retained_until !== null && $row->retained_until->lt(CarbonImmutable::now())) {
            throw new NotFoundHttpException('Artifact has expired and is no longer available.');
        }

        $disk = $this->filesystem->disk('local');

        if (! $disk->exists($row->path)) {
            throw new NotFoundHttpException('Artifact file missing on disk.');
        }

        $acceptsGzip = str_contains((string) $request->header('Accept-Encoding', ''), 'gzip');
        $filename = basename($row->path);
        // Inner extension is "<inner>.gz". Strip the .gz when serving inflated.
        $innerName = preg_replace('/\.gz$/', '', $filename) ?? $filename;

        if ($acceptsGzip) {
            return new StreamedResponse(
                function () use ($disk, $row): void {
                    $stream = $disk->readStream($row->path);
                    if ($stream === null) {
                        return;
                    }
                    fpassthru($stream);
                    fclose($stream);
                },
                200,
                [
                    'Content-Type' => 'application/octet-stream',
                    'Content-Encoding' => 'gzip',
                    'Content-Length' => (string) $row->size_bytes,
                    'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
                ],
            );
        }

        return new StreamedResponse(
            function () use ($disk, $row): void {
                $stream = $disk->readStream($row->path);
                if ($stream === null) {
                    return;
                }
                stream_filter_append($stream, 'zlib.inflate', STREAM_FILTER_READ, ['window' => 15 + 16]);
                fpassthru($stream);
                fclose($stream);
            },
            200,
            [
                'Content-Type' => 'application/octet-stream',
                'Content-Disposition' => sprintf('attachment; filename="%s"', $innerName),
            ],
        );
    }
}
