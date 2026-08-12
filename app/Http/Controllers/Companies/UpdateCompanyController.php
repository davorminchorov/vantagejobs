<?php

declare(strict_types=1);

namespace App\Http\Controllers\Companies;

use App\Enums\EventType;
use App\Http\Requests\Companies\UpdateCompanyRequest;
use App\Models\Company;
use App\Models\Event;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

final readonly class UpdateCompanyController
{
    public function __invoke(UpdateCompanyRequest $request, Company $company): RedirectResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $data['domain'] = $this->resolveDomain($data);

        $tierBefore = $company->tier;

        DB::transaction(function () use ($company, $data, $tierBefore): void {
            $company->fill($data)->save();

            $this->emitTierChangeEvent($company, $tierBefore, $company->tier);
        });

        return redirect()
            ->route('companies.index')
            ->with('success', "Updated {$company->name}.");
    }

    /**
     * The plain edit form lets `tier` reach or leave the 'graveyard' value
     * just like the dedicated graveyard/restore actions do, but previously
     * emitted nothing when it happened this way. Mirrors GRAVEYARDED /
     * RESTORED from GraveyardCompanyController / RestoreCompanyController
     * so the timeline is consistent regardless of which UI path made the
     * change. Only these two transitions are covered: no EventType case
     * exists yet for a plain tier bump (A/B/C) or a `status` change.
     */
    private function emitTierChangeEvent(Company $company, ?string $tierBefore, ?string $tierAfter): void
    {
        if ($tierBefore === $tierAfter) {
            return;
        }

        $now = CarbonImmutable::now();

        if ($tierAfter === 'graveyard') {
            Event::query()->create([
                'company_id' => $company->id,
                'role_id' => null,
                'type' => EventType::GRAVEYARDED->value,
                'title' => "Demoted to graveyard: {$company->name}",
                'body' => null,
                'occurred_at' => $now,
            ]);

            return;
        }

        if ($tierBefore === 'graveyard') {
            Event::query()->create([
                'company_id' => $company->id,
                'role_id' => null,
                'type' => EventType::RESTORED->value,
                'title' => "Restored from graveyard: {$company->name}",
                'body' => null,
                'occurred_at' => $now,
            ]);
        }
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
