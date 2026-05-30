<?php

declare(strict_types=1);

namespace App\Http\Controllers\Calendar;

use App\Enums\CalendarEventType;
use App\Http\Resources\Calendar\CalendarEventResource;
use App\Models\CalendarEvent;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final readonly class IndexController
{
    public function __invoke(Request $request): Response
    {
        // Default view shows everything from the start of today forward so the
        // board mirrors what the iCal feed exports (future-facing logbook),
        // with an optional ?past=1 escape hatch to see history.
        $includePast = $request->boolean('past');

        $query = CalendarEvent::query()->orderBy('starts_at');

        if (! $includePast) {
            $query->where('starts_at', '>=', CarbonImmutable::now()->startOfDay());
        }

        $events = $query->get();

        return Inertia::render('Calendar/Index', [
            'events' => CalendarEventResource::collection($events),
            'filters' => [
                'past' => $includePast,
            ],
            'options' => [
                'types' => array_map(
                    static fn (CalendarEventType $type): string => $type->value,
                    CalendarEventType::cases(),
                ),
                'owner_types' => ['role', 'company', 'contact'],
            ],
        ]);
    }
}
