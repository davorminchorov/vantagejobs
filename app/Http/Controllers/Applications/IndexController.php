<?php

declare(strict_types=1);

namespace App\Http\Controllers\Applications;

use App\Enums\ApplicationSource;
use App\Enums\CalendarEventType;
use App\Enums\PipelineStatus;
use App\Http\Resources\Calendar\CalendarEventResource;
use App\Http\Resources\Pipeline\RoleApplicationResource;
use App\Models\CalendarEvent;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Role;
use App\Models\Template;
use App\Pipeline\ApplicationAttention;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Applications workspace: a list of applications (roles in the pipeline)
 * grouped by attention or stage, an agenda of calendar events, and a detail
 * panel for the selected application. The props contract lives in
 * resources/js/components/applications/types.ts.
 */
final readonly class IndexController
{
    private const VIEWS = ['all', 'attention', 'upcoming'];

    private const AGENDA_STRIP_LIMIT = 5;

    private const AGENDA_LIST_LIMIT = 200;

    public function __construct(private ApplicationAttention $attention) {}

    public function __invoke(Request $request): Response
    {
        $view = $this->view($request);
        $stage = $this->stage($request);
        $past = $view === 'upcoming' && $request->boolean('past');
        $selectedId = $this->selectedId($request);
        $now = CarbonImmutable::now();

        $roles = Role::query()
            ->whereNotNull('pipeline_status')
            ->with('company:id,name')
            ->withExists('offer')
            ->get();

        $assessments = $this->attention->assess($roles);
        $nextEvents = $this->nextEventsByRole($roles, $now);

        $rows = $roles
            ->map(fn (Role $role): array => $this->row($role, $assessments[$role->id], $nextEvents[$role->id] ?? null))
            ->sort(static function (array $a, array $b): int {
                // Newest activity first; applications with no activity last.
                return [$b['last_activity_at'] !== null, (string) $b['last_activity_at'], $b['id']]
                    <=> [$a['last_activity_at'] !== null, (string) $a['last_activity_at'], $a['id']];
            })
            ->values();

        $applications = $rows
            ->when($view === 'attention', static fn (Collection $c): Collection => $c->filter(static fn (array $row): bool => $row['attention'] !== null))
            ->when($view !== 'upcoming' && $stage !== 'all', static fn (Collection $c): Collection => $c->filter(static fn (array $row): bool => $row['status'] === $stage))
            ->values()
            ->all();

        return Inertia::render('Applications/Index', [
            'filters' => [
                'view' => $view,
                'stage' => $stage,
                'past' => $past,
                'role' => $selectedId,
            ],
            'statuses' => array_map(
                static fn (PipelineStatus $status): array => [
                    'value' => $status->value,
                    'label' => $status->label(),
                    'terminal' => $status->isTerminal(),
                ],
                PipelineStatus::cases(),
            ),
            'application_sources' => array_map(
                static fn (ApplicationSource $source): string => $source->value,
                ApplicationSource::cases(),
            ),
            'event_types' => array_map(
                static fn (CalendarEventType $type): string => $type->value,
                CalendarEventType::cases(),
            ),
            'applications' => $applications,
            'counts' => $this->counts($rows, $now),
            'agenda' => $this->agenda($view, $past, $now),
            'selected' => fn (): ?array => $selectedId === null ? null : $this->selected($selectedId, $now),
            'templates' => Template::query()
                ->orderBy('name')
                ->get(['id', 'name', 'category'])
                ->map(static fn (Template $template): array => [
                    'id' => $template->id,
                    'name' => $template->name,
                    'category' => $template->category->value,
                ])
                ->values()
                ->all(),
            'follow_up_threshold_days' => $this->attention->thresholdDays(),
        ]);
    }

    private function view(Request $request): string
    {
        $view = $request->query('view');

        return is_string($view) && in_array($view, self::VIEWS, true) ? $view : 'all';
    }

    private function stage(Request $request): string
    {
        $stage = $request->query('stage');

        return is_string($stage) && PipelineStatus::tryFrom($stage) !== null ? $stage : 'all';
    }

    private function selectedId(Request $request): ?int
    {
        $role = $request->query('role');

        if (! is_string($role) && ! is_int($role)) {
            return null;
        }

        $id = filter_var($role, FILTER_VALIDATE_INT);

        return $id !== false && $id > 0 ? $id : null;
    }

    /**
     * @param  array{attention: \App\Enums\AttentionReason|null, last_activity_at: CarbonImmutable|null, days_quiet: int|null}  $assessment
     * @param  array<string, mixed>|null  $nextEvent
     * @return array<string, mixed>
     */
    private function row(Role $role, array $assessment, ?array $nextEvent): array
    {
        $status = $role->pipeline_status;

        return [
            'id' => $role->id,
            'title' => $role->title,
            'company' => $role->company === null ? null : [
                'id' => $role->company->id,
                'name' => $role->company->name,
            ],
            'status' => $status?->value,
            'applied_at' => $role->applied_at?->toIso8601String(),
            'next_action' => $role->next_action,
            'next_action_date' => $role->next_action_date?->toDateString(),
            'last_activity_at' => $assessment['last_activity_at']?->toIso8601String(),
            'attention' => $assessment['attention']?->value,
            'days_quiet' => $assessment['days_quiet'],
            'next_event' => $nextEvent,
            'has_offer' => (bool) $role->getAttribute('offer_exists'),
        ];
    }

    /**
     * Earliest upcoming event owned by each role, one query for all roles.
     *
     * @param  EloquentCollection<int, Role>  $roles
     * @return array<int, array{id: int, title: string, starts_at: string|null, timezone: string, type: string|null}>
     */
    private function nextEventsByRole(EloquentCollection $roles, CarbonImmutable $now): array
    {
        if ($roles->isEmpty()) {
            return [];
        }

        $events = CalendarEvent::query()
            ->where('owner_type', 'role')
            ->whereIn('owner_id', $roles->pluck('id')->all())
            ->where('starts_at', '>=', $now)
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();

        $byRole = [];
        foreach ($events as $event) {
            $roleId = (int) $event->owner_id;
            $byRole[$roleId] ??= [
                'id' => $event->id,
                'title' => $event->title,
                'starts_at' => $event->starts_at?->utc()->toIso8601String(),
                'timezone' => $event->timezone,
                'type' => $event->type?->value,
            ];
        }

        return $byRole;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows  every application, unfiltered
     * @return array{active: int, attention: int, upcoming: int, closed: int}
     */
    private function counts(Collection $rows, CarbonImmutable $now): array
    {
        $closed = $rows->filter(static fn (array $row): bool => PipelineStatus::from($row['status'])->isTerminal())->count();

        return [
            'active' => $rows->count() - $closed,
            'attention' => $rows->filter(static fn (array $row): bool => $row['attention'] !== null)->count(),
            'upcoming' => CalendarEvent::query()->where('starts_at', '>=', $now)->count(),
            'closed' => $closed,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function agenda(string $view, bool $past, CarbonImmutable $now): array
    {
        $query = CalendarEvent::query();

        if ($view === 'upcoming') {
            $past
                ? $query->where('starts_at', '<', $now)->orderByDesc('starts_at')->orderByDesc('id')
                : $query->where('starts_at', '>=', $now)->orderBy('starts_at')->orderBy('id');
            $query->limit(self::AGENDA_LIST_LIMIT);
        } else {
            $query->where('starts_at', '>=', $now)->orderBy('starts_at')->orderBy('id')->limit(self::AGENDA_STRIP_LIMIT);
        }

        return $this->agendaEvents($query->get());
    }

    /**
     * Shape events with their owner resolved in one query per owner type.
     *
     * @param  EloquentCollection<int, CalendarEvent>  $events
     * @return array<int, array<string, mixed>>
     */
    private function agendaEvents(EloquentCollection $events): array
    {
        $idsOf = static fn (string $type): array => $events
            ->where('owner_type', $type)
            ->pluck('owner_id')
            ->filter()
            ->unique()
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        $roleIds = $idsOf('role');
        $companyIds = $idsOf('company');
        $contactIds = $idsOf('contact');

        $roles = $roleIds === [] ? collect() : Role::query()->whereIn('id', $roleIds)->with('company:id,name')->get()->keyBy('id');
        $companies = $companyIds === [] ? collect() : Company::query()->whereIn('id', $companyIds)->get(['id', 'name'])->keyBy('id');
        $contacts = $contactIds === [] ? collect() : Contact::query()->whereIn('id', $contactIds)->get(['id', 'name'])->keyBy('id');

        return $events
            ->map(function (CalendarEvent $event) use ($roles, $companies, $contacts): array {
                $ownerId = $event->owner_id === null ? null : (int) $event->owner_id;

                return [
                    ...(new CalendarEventResource($event))->resolve(),
                    'owner' => $ownerId === null ? null : match ($event->owner_type) {
                        'role' => $this->roleOwner($roles->get($ownerId)),
                        'company' => $this->namedOwner('company', $companies->get($ownerId)?->name, $ownerId, route('companies.show', $ownerId, false)),
                        'contact' => $this->namedOwner('contact', $contacts->get($ownerId)?->name, $ownerId, route('contacts.show', $ownerId, false)),
                        default => null,
                    },
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array{type: string, id: int, label: string, role_id: int|null, href: string|null}|null
     */
    private function roleOwner(?Role $role): ?array
    {
        if ($role === null) {
            return null;
        }

        $companyName = $role->company?->name;

        return [
            'type' => 'role',
            'id' => $role->id,
            'label' => $companyName === null ? (string) $role->title : "{$role->title}, {$companyName}",
            'role_id' => $role->pipeline_status !== null ? $role->id : null,
            'href' => null,
        ];
    }

    /**
     * @return array{type: string, id: int, label: string, role_id: null, href: string}|null
     */
    private function namedOwner(string $type, ?string $name, int $id, string $href): ?array
    {
        if ($name === null) {
            return null;
        }

        return ['type' => $type, 'id' => $id, 'label' => $name, 'role_id' => null, 'href' => $href];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function selected(int $roleId, CarbonImmutable $now): ?array
    {
        $role = Role::query()->with(['company', 'stageNotes', 'offer.revisions'])->find($roleId);

        if ($role === null) {
            return null;
        }

        $inPipeline = $role->pipeline_status !== null;
        $attention = $inPipeline ? ($this->attention->assess([$role])[$role->id]['attention'] ?? null) : null;

        $events = CalendarEvent::query()
            ->where('owner_type', 'role')
            ->where('owner_id', $role->id)
            ->get();
        $upcoming = $events->filter(static fn (CalendarEvent $e): bool => $e->starts_at >= $now)->sortBy('starts_at');
        $earlier = $events->filter(static fn (CalendarEvent $e): bool => $e->starts_at < $now)->sortByDesc('starts_at');

        return [
            'role' => (new RoleApplicationResource($role))->resolve(),
            'in_pipeline' => $inPipeline,
            'attention' => $attention?->value,
            'contacts' => $this->companyContacts($role, $now),
            'referral_options' => Contact::query()
                ->orderBy('name')
                ->get(['id', 'name', 'role_title'])
                ->map(static fn (Contact $contact): array => [
                    'id' => $contact->id,
                    'name' => $contact->name,
                    'role_title' => $contact->role_title,
                ])
                ->values()
                ->all(),
            'events' => $this->agendaEvents(new EloquentCollection($upcoming->concat($earlier)->values()->all())),
        ];
    }

    /**
     * Contacts at the role's company, never-touched first, then oldest touch first.
     *
     * @return array<int, array<string, mixed>>
     */
    private function companyContacts(Role $role, CarbonImmutable $now): array
    {
        if ($role->company_id === null) {
            return [];
        }

        return Contact::query()
            ->where('company_id', $role->company_id)
            ->orderByRaw('last_touch is null desc')
            ->orderBy('last_touch')
            ->orderBy('name')
            ->get()
            ->map(static fn (Contact $contact): array => [
                'id' => $contact->id,
                'name' => $contact->name,
                'role_title' => $contact->role_title,
                'email' => $contact->email,
                'linkedin_url' => $contact->linkedin_url,
                'last_touch' => $contact->last_touch?->toIso8601String(),
                'days_since_touch' => $contact->last_touch === null
                    ? null
                    : (int) $contact->last_touch->startOfDay()->diffInDays($now->startOfDay()),
            ])
            ->values()
            ->all();
    }
}
