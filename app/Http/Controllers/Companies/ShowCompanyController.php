<?php

declare(strict_types=1);

namespace App\Http\Controllers\Companies;

use App\Models\Company;
use App\Models\Event;
use App\Models\Note;
use Carbon\CarbonImmutable;
use Inertia\Inertia;
use Inertia\Response;

final readonly class ShowCompanyController
{
    public function __invoke(Company $company): Response
    {
        $roles = $company->roles()
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

        $openCount = $company->roles()->where('is_open', true)->count();
        $totalCount = $company->roles()->count();

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
                'last_checked_at' => $company->last_checked_at?->toIso8601String(),
                'etag' => $company->etag,
                'last_modified' => $company->last_modified,
                'created_at' => $company->created_at?->toIso8601String(),
            ],
            'roles' => $roles,
            'timeline' => $timeline,
            'counts' => [
                'open_roles' => $openCount,
                'total_roles' => $totalCount,
                'timeline_items' => $timeline->count(),
            ],
        ]);
    }
}
