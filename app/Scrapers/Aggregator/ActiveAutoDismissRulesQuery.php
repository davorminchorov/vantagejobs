<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator;

use App\Models\DismissedCompany;

/**
 * Reads the currently-active auto-dismiss rules for surfacing on the
 * dashboard banner so the user doesn't forget the rule exists (PRD §6.4.4).
 *
 * Tenant scoping is implicit via BelongsToTenant on DismissedCompany.
 */
final readonly class ActiveAutoDismissRulesQuery
{
    /**
     * @return list<array{display_name: string, normalized_company_key: string, dismiss_count: int}>
     */
    public function active(): array
    {
        return DismissedCompany::query()
            ->where('auto_dismiss', true)
            ->orderByDesc('dismiss_count')
            ->orderBy('display_name')
            ->get(['display_name', 'normalized_company_key', 'dismiss_count'])
            ->map(static fn (DismissedCompany $row): array => [
                'display_name' => $row->display_name,
                'normalized_company_key' => $row->normalized_company_key,
                'dismiss_count' => $row->dismiss_count,
            ])
            ->all();
    }
}
