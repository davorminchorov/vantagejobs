<?php

declare(strict_types=1);

namespace App\Http\Controllers\Companies;

use App\Enums\AtsType;
use Inertia\Inertia;
use Inertia\Response;

final readonly class CreateCompanyController
{
    public function __invoke(): Response
    {
        return Inertia::render('Companies/Create', [
            'options' => $this->options(),
        ]);
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function options(): array
    {
        return [
            'tiers' => ['A', 'B', 'C', 'graveyard'],
            'statuses' => ['researching', 'watching', 'applied', 'in_process', 'rejected', 'offer', 'passed'],
            'ats_types' => array_map(fn (AtsType $t) => $t->value, AtsType::cases()),
        ];
    }
}
