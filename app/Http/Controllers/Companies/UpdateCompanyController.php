<?php

declare(strict_types=1);

namespace App\Http\Controllers\Companies;

use App\Http\Requests\Companies\UpdateCompanyRequest;
use App\Models\Company;
use Illuminate\Http\RedirectResponse;

final readonly class UpdateCompanyController
{
    public function __invoke(UpdateCompanyRequest $request, Company $company): RedirectResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $data['domain'] = $this->resolveDomain($data);

        $company->fill($data)->save();

        return redirect()
            ->route('companies.index')
            ->with('success', "Updated {$company->name}.");
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveDomain(array $data): ?string
    {
        if (isset($data['domain']) && is_string($data['domain']) && $data['domain'] !== '') {
            return mb_strtolower($data['domain']);
        }

        $website = $data['website'] ?? null;
        if (! is_string($website) || $website === '') {
            return null;
        }

        $host = parse_url($website, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return null;
        }

        return mb_strtolower(preg_replace('/^www\./i', '', $host) ?? $host);
    }
}
