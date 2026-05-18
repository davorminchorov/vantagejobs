<?php

declare(strict_types=1);

namespace App\Http\Controllers\Companies;

use App\Enums\EventType;
use App\Http\Requests\Companies\GraveyardCompanyRequest;
use App\Models\Company;
use App\Models\CompanyGraveyardReason;
use App\Models\Event;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

final readonly class GraveyardCompanyController
{
    public function __invoke(GraveyardCompanyRequest $request, Company $company): RedirectResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();
        $reason = is_string($data['reason'] ?? null) ? trim($data['reason']) : null;
        $tags = array_values(array_unique(array_filter(
            (array) ($data['tags'] ?? []),
            static fn ($t): bool => is_string($t) && $t !== '',
        )));

        $now = CarbonImmutable::now();

        DB::transaction(function () use ($company, $request, $reason, $tags, $now): void {
            $company->update([
                'status' => 'graveyard',
                'is_active' => false,
            ]);

            CompanyGraveyardReason::query()->create([
                'company_id' => $company->id,
                'demoted_by_user_id' => $request->user()?->id,
                'demoted_at' => $now,
                'reason' => $reason ?: null,
                'tags' => $tags === [] ? null : $tags,
            ]);

            Event::query()->create([
                'company_id' => $company->id,
                'role_id' => null,
                'type' => EventType::GRAVEYARDED->value,
                'title' => "Demoted to graveyard: {$company->name}",
                'body' => $reason,
                'occurred_at' => $now,
            ]);
        });

        return redirect()
            ->route('companies.show', $company)
            ->with('success', "Moved {$company->name} to the graveyard.");
    }
}
