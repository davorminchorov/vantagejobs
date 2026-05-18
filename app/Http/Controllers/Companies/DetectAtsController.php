<?php

declare(strict_types=1);

namespace App\Http\Controllers\Companies;

use App\Scrapers\AtsDetector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sniff a URL for an ATS signature. Used by the company create/edit form
 * to pre-fill ats_type + ats_slug when the user pastes a careers URL.
 */
final readonly class DetectAtsController
{
    public function __construct(private AtsDetector $detector) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'string', 'max:500'],
        ]);

        $result = $this->detector->detect($validated['url']);

        return new JsonResponse([
            'detected' => $result,
        ]);
    }
}
