<?php

declare(strict_types=1);

namespace App\Http\Controllers\Companies;

use App\Models\Company;
use App\Models\CompanyGraveyardReason;
use App\Models\CompanySnapshot;
use App\Models\Event;
use App\Models\Note;
use App\Models\Role;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final readonly class ShowCompanyController
{
    public function __invoke(Request $request, Company $company): Response
    {
        $rolesQ = $this->nonEmptyString($request->query('roles_q'));
        $rolesState = $this->inSet($request->query('roles_state'), ['open', 'closed']);

        $roles = $company->roles()
            ->when($rolesQ !== null, fn ($query) => $query->where('title', 'ilike', '%'.$rolesQ.'%'))
            ->when($rolesState === 'open', fn ($query) => $query->where('is_open', true))
            ->when($rolesState === 'closed', fn ($query) => $query->where('is_open', false))
            ->orderByDesc('is_open')
            ->orderByDesc('last_seen_at')
            ->orderByDesc('posted_at')
            ->paginate(15, pageName: 'roles_page')
            ->withQueryString();

        $events = Event::query()
            ->where('company_id', $company->id)
            ->orderByDesc('occurred_at')
            ->limit(50)
            ->get(['id', 'type', 'title', 'body', 'occurred_at', 'role_id'])
            ->map(fn (Event $e) => [
                'kind' => 'event',
                'id' => "event-{$e->id}",
                'type' => $e->type,
                'title' => $e->title,
                'body' => $e->body,
                'role_id' => $e->role_id,
                'at' => $e->occurred_at?->toIso8601String(),
            ]);

        $notes = Note::query()
            ->where('company_id', $company->id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get(['id', 'body', 'created_at'])
            ->map(fn (Note $n) => [
                'kind' => 'note',
                'id' => "note-{$n->id}",
                'type' => 'note',
                'title' => 'Note',
                'body' => $n->body,
                'role_id' => null,
                'at' => $n->created_at?->toIso8601String(),
            ]);

        $timeline = $events->merge($notes)
            ->sortByDesc('at')
            ->values()
            ->take(50);

        $roleIds = $timeline->pluck('role_id')->filter()->unique()->values();
        $roleTitles = Role::query()->whereIn('id', $roleIds)->pluck('title', 'id');

        $timeline = $timeline->map(function (array $item) use ($roleTitles) {
            $item['role_title'] = $item['role_id'] !== null ? ($roleTitles->get($item['role_id']) ?? null) : null;

            return $item;
        });

        $openCount = $company->roles()->where('is_open', true)->count();
        $totalCount = $company->roles()->count();

        $tags = $company->tags()
            ->orderBy('name')
            ->get(['tags.id', 'tags.slug', 'tags.name'])
            ->map(fn ($tag) => ['id' => $tag->id, 'slug' => $tag->slug, 'name' => $tag->name])
            ->values();

        $latestSnapshot = CompanySnapshot::query()
            ->where('company_id', $company->id)
            ->orderByDesc('snapshot_date')
            ->first();

        $snapshotSince = CarbonImmutable::now()->subDays(90)->toDateString();
        $snapshots = CompanySnapshot::query()
            ->where('company_id', $company->id)
            ->where('snapshot_date', '>=', $snapshotSince)
            ->orderBy('snapshot_date')
            ->get(['snapshot_date', 'open_role_count', 'listings_count'])
            ->map(fn (CompanySnapshot $s) => [
                'date' => $s->snapshot_date?->toDateString(),
                'open_role_count' => $s->open_role_count,
                'listings_count' => $s->listings_count,
            ])
            ->values();

        $graveyardReason = CompanyGraveyardReason::query()
            ->where('company_id', $company->id)
            ->whereNull('restored_at')
            ->latest('demoted_at')
            ->first();

        return Inertia::render('Companies/Show', [
            'company' => [
                'id' => $company->id,
                'name' => $company->name,
                'website' => $company->website,
                'careers_url' => $company->careers_url,
                'domain' => $company->domain,
                'ats_type' => $company->ats_type,
                'ats_slug' => $company->ats_slug,
                'tier' => $company->tier,
                'status' => $company->status,
                'is_active' => (bool) $company->is_active,
                'last_checked_at' => $company->last_checked_at?->toIso8601String(),
                'etag' => $company->etag,
                'last_modified' => $company->last_modified,
                'created_at' => $company->created_at?->toIso8601String(),
                'funding_stage' => $company->funding_stage,
                'funding_last_raised_at' => $company->funding_last_raised_at?->toIso8601String(),
                'funding_last_amount_usd' => $company->funding_last_amount_usd,
                'github_org' => $company->github_org,
                'eng_blog_rss_url' => $company->eng_blog_rss_url,
            ],
            'roles' => $roles,
            'roles_filters' => [
                'q' => $rolesQ,
                'state' => $rolesState,
            ],
            'timeline' => $timeline,
            'tags' => $tags,
            'counts' => [
                'open_roles' => $openCount,
                'total_roles' => $totalCount,
                'timeline_items' => $timeline->count(),
            ],
            'graveyard' => $graveyardReason === null ? null : [
                'demoted_at' => $graveyardReason->demoted_at?->toIso8601String(),
                'reason' => $graveyardReason->reason,
                'tags' => $graveyardReason->tags ?? [],
            ],
            'graveyard_preset_tags' => CompanyGraveyardReason::PRESET_TAGS,
            'hiring_trend' => $snapshots,
            'quality' => $latestSnapshot === null || $latestSnapshot->quality_score === null ? null : [
                'score' => $latestSnapshot->quality_score,
                'breakdown' => $latestSnapshot->breakdown ?? [],
                'snapshot_date' => $latestSnapshot->snapshot_date?->toDateString(),
            ],
        ]);
    }

    private function nonEmptyString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param  list<string>  $set
     */
    private function inSet(mixed $value, array $set): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return in_array($value, $set, true) ? $value : null;
    }
}
