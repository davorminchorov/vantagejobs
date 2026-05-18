<?php

declare(strict_types=1);

namespace App\Http\Controllers\Import;

use App\Http\Requests\Import\UploadCsvRequest;
use App\Imports\CsvImporter;
use Illuminate\Http\RedirectResponse;
use SplFileObject;

final readonly class UploadCsvController
{
    public function __construct(private CsvImporter $importer) {}

    public function __invoke(UploadCsvRequest $request): RedirectResponse
    {
        $file = $request->file('file');
        if ($file === null) {
            return back()->withErrors(['file' => 'Upload failed.']);
        }

        $spl = new SplFileObject($file->getRealPath());
        $parsed = $this->importer->parse($spl);

        $request->session()->put([
            'import.headers' => $parsed['headers'],
            'import.rows' => $parsed['rows'],
            'import.original_name' => $file->getClientOriginalName(),
            'import.mapping' => $this->importer->detectMapping($parsed['headers']),
        ]);

        return redirect()->route('import.map');
    }
}
