<?php

declare(strict_types=1);

namespace App\Http\Controllers\Companies;

use App\Enums\AtsType;
use App\Models\Company;
use Inertia\Inertia;
use Inertia\Response;

final readonly class EditCompanyController
{
    public function __invoke(Company $company): Response
    {
        return Inertia::render('Companies/Edit', [
            'company' => [
                'id' => $company->id,
                'name' => $company->name,
                'website' => $company->website,
                'careers_url' => $company->careers_url,
                'domain' => $company->domain,
                'ats_type' => $company->ats_type,
                'ats_slug' => $company->ats_slug,
                'ats_host' => $company->ats_host,
                'tier' => $company->tier,
                'status' => $company->status,
            ],
            'options' => [
                'tiers' => ['A', 'B', 'C', 'graveyard'],
                'statuses' => ['researching', 'watching', 'applied', 'in_process', 'rejected', 'offer', 'passed'],
                'ats_types' => array_map(fn (AtsType $t) => $t->value, AtsType::cases()),
            ],
        ]);
    }
}
