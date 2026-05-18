<?php

declare(strict_types=1);

namespace App\Http\Controllers\Companies;

use App\Enums\EventType;
use App\Http\Requests\Companies\StoreCompanyRequest;
use App\Models\Company;
use App\Models\Event;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

final readonly class StoreCompanyController
{
    public function __invoke(StoreCompanyRequest $request): RedirectResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $data['domain'] = $this->resolveDomain($data);
        $data['tier'] ??= 'C';
        $data['status'] ??= 'researching';

        $company = DB::transaction(function () use ($data): Company {
            $company = Company::query()->create($data);

            Event::query()->create([
                'company_id' => $company->id,
                'role_id' => null,
                'type' => EventType::COMPANY_ADDED->value,
                'title' => "Added to watch list: {$company->name}",
                'body' => null,
                'occurred_at' => CarbonImmutable::now(),
            ]);

            return $company;
        });

        return redirect()
            ->route('companies.index')
            ->with('success', "Added {$company->name} to the watch list.");
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
