<?php

declare(strict_types=1);

namespace App\Http\Controllers\Import;

use App\Imports\CsvImporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final readonly class MapFormController
{
    public function __construct(private CsvImporter $importer) {}

    public function __invoke(Request $request): Response|RedirectResponse
    {
        $headers = $request->session()->get('import.headers');
        $rows = $request->session()->get('import.rows');
        $mapping = $request->session()->get('import.mapping');
        $originalName = $request->session()->get('import.original_name');

        if (! is_array($headers) || ! is_array($rows) || ! is_array($mapping)) {
            return redirect()->route('import.upload')
                ->with('error', 'Please upload a CSV first.');
        }

        return Inertia::render('Import/Map', [
            'headers' => $headers,
            'mapping' => $mapping,
            'preview' => $this->importer->preview($headers, $rows, $mapping, 10),
            'row_count' => count($rows),
            'original_name' => $originalName,
            'fields' => CsvImporter::FIELDS,
            'required_fields' => CsvImporter::REQUIRED_FIELDS,
        ]);
    }
}
