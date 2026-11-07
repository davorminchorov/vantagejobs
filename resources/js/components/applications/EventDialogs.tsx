import { router } from '@inertiajs/react';

import CalendarEventForm, { type OwnerOption } from '@/components/calendar/CalendarEventForm';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';

import { toLocalInputValue } from './helpers';
import type { AgendaEvent } from './types';

export type EventFormState = { mode: 'closed' } | { mode: 'create' } | { mode: 'edit'; event: AgendaEvent };

interface EventFormDialogProps {
    state: EventFormState;
    onClose: () => void;
    eventTypes: string[];
    /** Applications an event can be attached to. */
    ownerOptions: OwnerOption[];
    /** Fixed owner for events created or edited from an application's panel. */
    lockedOwner?: OwnerOption;
    /** Owner preselected when creating (ignored when editing or locked). */
    defaultOwner?: string;
}

export function EventFormDialog({ state, onClose, eventTypes, ownerOptions, lockedOwner, defaultOwner }: EventFormDialogProps) {
    const editing = state.mode === 'edit' ? state.event : null;

    let options = lockedOwner ? [lockedOwner] : ownerOptions;
    if (!lockedOwner && editing?.owner_type && editing.owner_id !== null) {
        const value = `${editing.owner_type}:${editing.owner_id}`;
        if (!options.some((option) => option.value === value)) {
            options = [...options, { value, label: editing.owner?.label ?? `${editing.owner_type} ${editing.owner_id}` }];
        }
    }

    const [defaultType = '', defaultId = ''] = (defaultOwner ?? '').split(':');
    const initialOwner = lockedOwner
        ? { owner_type: lockedOwner.value.split(':')[0] ?? '', owner_id: lockedOwner.value.split(':')[1] ?? '' }
        : { owner_type: editing?.owner_type ?? '', owner_id: editing?.owner_id !== null && editing ? String(editing.owner_id) : '' };
    const createOwner = editing ? initialOwner : lockedOwner ? initialOwner : { owner_type: defaultType, owner_id: defaultId };

    return (
        <Dialog open={state.mode !== 'closed'} onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-h-[calc(100dvh-2rem)] max-w-2xl overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>{editing ? 'Edit event' : 'Add event'}</DialogTitle>
                    <DialogDescription>
                        Times are shown in the timezone you enter. The reminder fires that many minutes before the event.
                    </DialogDescription>
                </DialogHeader>
                {state.mode !== 'closed' ? (
                    <CalendarEventForm
                        key={editing ? `edit-${editing.id}` : 'create'}
                        mode={editing ? 'edit' : 'create'}
                        types={eventTypes}
                        ownerOptions={options}
                        ownerLocked={lockedOwner !== undefined}
                        initial={
                            editing
                                ? {
                                      title: editing.title,
                                      starts_at: toLocalInputValue(editing.starts_at, editing.timezone),
                                      timezone: editing.timezone,
                                      type: editing.type,
                                      ...initialOwner,
                                      url: editing.url ?? '',
                                      notes: editing.notes ?? '',
                                      reminder_minutes: editing.reminder_minutes !== null ? String(editing.reminder_minutes) : '',
                                  }
                                : createOwner
                        }
                        action={editing ? `/calendar/${editing.id}` : '/calendar'}
                        method={editing ? 'put' : 'post'}
                        onCancel={onClose}
                        onSuccess={onClose}
                    />
                ) : null}
            </DialogContent>
        </Dialog>
    );
}

interface DeleteEventDialogProps {
    event: AgendaEvent | null;
    onClose: () => void;
}

export function DeleteEventDialog({ event, onClose }: DeleteEventDialogProps) {
    function confirmDelete() {
        if (event === null) {
            return;
        }
        router.delete(`/calendar/${event.id}`, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: onClose,
        });
    }

    return (
        <Dialog open={event !== null} onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Delete event</DialogTitle>
                    <DialogDescription>{event ? `Delete "${event.title}"? This cannot be undone.` : ''}</DialogDescription>
                </DialogHeader>
                <div className="flex items-center justify-end gap-3">
                    <Button type="button" variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button type="button" variant="destructive" onClick={confirmDelete}>
                        Delete
                    </Button>
                </div>
            </DialogContent>
        </Dialog>
    );
}
