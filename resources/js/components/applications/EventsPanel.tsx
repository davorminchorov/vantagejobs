import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { SectionLabel } from '@/components/ui/section-label';

import { DeleteEventDialog, EventFormDialog, type EventFormState } from './EventDialogs';
import { EventRow } from './EventRow';
import type { AgendaEvent } from './types';

interface EventsPanelProps {
    roleId: number;
    roleTitle: string;
    events: AgendaEvent[];
    eventTypes: string[];
}

/** Events owned by one application, with the owner fixed to that application. */
export function EventsPanel({ roleId, roleTitle, events, eventTypes }: EventsPanelProps) {
    const [formState, setFormState] = useState<EventFormState>({ mode: 'closed' });
    const [pendingDelete, setPendingDelete] = useState<AgendaEvent | null>(null);

    return (
        <section className="flex flex-col gap-3">
            <div className="flex items-center justify-between gap-2">
                <SectionLabel>Events</SectionLabel>
                <Button type="button" variant="outline" size="sm" onClick={() => setFormState({ mode: 'create' })}>
                    Add event
                </Button>
            </div>

            {events.length === 0 ? (
                <p className="text-base text-pretty text-muted-foreground sm:text-sm">
                    No events yet. Add interviews, deadlines and reminders for this application.
                </p>
            ) : (
                <ul role="list" className="divide-y divide-border border-y border-border">
                    {events.map((event) => (
                        <EventRow
                            key={event.id}
                            event={event}
                            showOwner={false}
                            onEdit={(target) => setFormState({ mode: 'edit', event: target })}
                            onDelete={setPendingDelete}
                        />
                    ))}
                </ul>
            )}

            <EventFormDialog
                state={formState}
                onClose={() => setFormState({ mode: 'closed' })}
                eventTypes={eventTypes}
                ownerOptions={[]}
                lockedOwner={{ value: `role:${roleId}`, label: roleTitle }}
            />
            <DeleteEventDialog event={pendingDelete} onClose={() => setPendingDelete(null)} />
        </section>
    );
}
