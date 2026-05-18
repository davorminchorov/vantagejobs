<?php

declare(strict_types=1);

namespace App\Http\Controllers\Import;

use App\Http\Requests\Import\RunImportRequest;
use App\Imports\CsvImporter;
use Illuminate\Http\RedirectResponse;

final readonly class RunImportController
{
    public function __construct(private CsvImporter $importer) {}

    public function __invoke(RunImportRequest $request): RedirectResponse
    {
        $headers = $request->session()->get('import.headers');
        $rows = $request->session()->get('import.rows');

        if (! is_array($headers) || ! is_array($rows)) {
            return redirect()->route('import.upload')
                ->with('error', 'Import session expired. Please upload again.');
        }

        /** @var array<string, ?string> $mapping */
        $mapping = $request->validated('mapping');

        $summary = $this->importer->run($headers, $rows, $mapping);

        $request->session()->forget(['import.headers', 'import.rows', 'import.mapping', 'import.original_name']);

        return redirect()
            ->route('companies.index')
            ->with('success', sprintf(
                'Import complete: %d created, %d skipped (duplicate domain), %d failed.',
                $summary['created'],
                $summary['skipped'],
                $summary['failed'],
            ))
            ->with('import.summary', $summary);
    }
}
