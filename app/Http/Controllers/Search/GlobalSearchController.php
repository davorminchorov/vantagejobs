<?php

declare(strict_types=1);

namespace App\Http\Controllers\Search;

use App\Models\Company;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cmd-K backing endpoint. Uses Postgres FTS via the generated tsvector
 * columns from migration 0015 and the GIN indexes on
 * companies.search and roles.search.
 *
 * Per-token prefix matching (token:*) is applied so the palette feels
 * autocomplete-fast as the user types. ts_rank_cd orders results
 * within each group.
 */
final readonly class GlobalSearchController
{
    public function __invoke(Request $request): JsonResponse
    {
        $raw = (string) $request->query('q', '');
        $query = $this->buildTsQuery($raw);

        if ($query === '') {
            return new JsonResponse(['companies' => [], 'roles' => []]);
        }

        $companies = Company::query()
            ->select(['id', 'name', 'domain', 'ats_type', 'ats_slug', 'tier'])
            ->selectRaw("ts_rank_cd(search, to_tsquery('english', ?)) as rank", [$query])
            ->whereRaw("search @@ to_tsquery('english', ?)", [$query])
            ->orderByDesc('rank')
            ->orderBy('name')
            ->limit(8)
            ->get();

        $roles = Role::query()
            ->select(['id', 'title', 'is_open', 'company_id', 'workplace_type'])
            ->selectRaw("ts_rank_cd(search, to_tsquery('english', ?)) as rank", [$query])
            ->whereRaw("search @@ to_tsquery('english', ?)", [$query])
            ->orderByDesc('rank')
            ->limit(8)
            ->with(['company:id,name'])
            ->get();

        return new JsonResponse([
            'companies' => $companies->map(fn (Company $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'domain' => $c->domain,
                'ats_type' => $c->ats_type,
                'tier' => $c->tier,
                'url' => "/companies/{$c->id}",
            ])->values(),
            'roles' => $roles->map(fn (Role $r) => [
                'id' => $r->id,
                'title' => $r->title,
                'is_open' => (bool) $r->is_open,
                'company_id' => $r->company_id,
                'company_name' => $r->company?->name,
                'workplace_type' => $r->workplace_type?->value,
                'url' => "/companies/{$r->company_id}",
            ])->values(),
        ]);
    }

    /**
     * Sanitize user input into a safe to_tsquery payload with per-token
     * prefix matching. Returns '' when the query has no usable tokens.
     */
    private function buildTsQuery(string $raw): string
    {
        $cleaned = preg_replace('/[^a-z0-9\s]/i', ' ', $raw) ?? '';
        $tokens = preg_split('/\s+/', mb_strtolower(trim($cleaned)), -1, PREG_SPLIT_NO_EMPTY);

        if (! is_array($tokens) || $tokens === []) {
            return '';
        }

        return implode(' & ', array_map(fn (string $t): string => $t.':*', $tokens));
    }
}
