<?php

declare(strict_types=1);

namespace App\Http\Controllers\Companies;

use App\Enums\EventType;
use App\Models\Company;
use App\Models\CompanyGraveyardReason;
use App\Models\Event;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final readonly class RestoreCompanyController
{
    public function __invoke(Request $request, Company $company): RedirectResponse
    {
        $now = CarbonImmutable::now();

        DB::transaction(function () use ($company, $request, $now): void {
            $company->update([
                'status' => 'researching',
                'tier' => $company->tier === 'graveyard' ? 'C' : $company->tier,
                'is_active' => true,
            ]);

            CompanyGraveyardReason::query()
                ->where('company_id', $company->id)
                ->whereNull('restored_at')
                ->latest('demoted_at')
                ->limit(1)
                ->update([
                    'restored_at' => $now,
                    'restored_by_user_id' => $request->user()?->id,
                ]);

            Event::query()->create([
                'company_id' => $company->id,
                'role_id' => null,
                'type' => EventType::RESTORED->value,
                'title' => "Restored from graveyard: {$company->name}",
                'body' => null,
                'occurred_at' => $now,
            ]);
        });

        return redirect()
            ->route('companies.show', $company)
            ->with('success', "Restored {$company->name} to C-tier.");
    }
}
