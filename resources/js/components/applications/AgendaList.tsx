import { useMemo } from 'react';

import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';

import { EventRow } from './EventRow';
import { groupAgendaByDay } from './helpers';
import type { AgendaEvent } from './types';

interface AgendaListProps {
    events: AgendaEvent[];
    past: boolean;
    onTogglePast: () => void;
    onAdd: () => void;
    onOpenApplication: (roleId: number) => void;
    onEdit: (event: AgendaEvent) => void;
    onDelete: (event: AgendaEvent) => void;
}

/** Left pane of the Upcoming view: every event, grouped by day. */
export function AgendaList({ events, past, onTogglePast, onAdd, onOpenApplication, onEdit, onDelete }: AgendaListProps) {
    const days = useMemo(() => groupAgendaByDay(events), [events]);

    return (
        <section className="flex flex-col gap-4" aria-label={past ? 'Past events' : 'Upcoming events'}>
            <div className="flex flex-wrap items-center justify-between gap-2">
                <Button type="button" variant="outline" size="sm" onClick={onTogglePast} aria-pressed={past}>
                    {past ? 'Show upcoming events' : 'Show past events'}
                </Button>
                <Button type="button" size="sm" onClick={onAdd}>
                    Add event
                </Button>
            </div>

            {days.length === 0 ? (
                <EmptyState>
                    {past
                        ? 'No past events on record.'
                        : 'Nothing is scheduled. Add interviews, deadlines and reminders here, or subscribe to the calendar feed from settings.'}
                </EmptyState>
            ) : (
                days.map((day) => (
                    <div key={day.key} className="flex flex-col">
                        <h2 className="text-sm font-medium text-muted-foreground">{day.label}</h2>
                        <ul role="list" className="divide-y divide-border border-b border-border">
                            {day.events.map((event) => (
                                <EventRow
                                    key={event.id}
                                    event={event}
                                    timeOnly
                                    onOpenApplication={onOpenApplication}
                                    onEdit={onEdit}
                                    onDelete={onDelete}
                                />
                            ))}
                        </ul>
                    </div>
                ))
            )}
        </section>
    );
}
