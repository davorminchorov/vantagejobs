import { Head, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/components/PageHeader';
import CalendarEventForm, { humanizeType } from '@/components/calendar/CalendarEventForm';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';

interface CalendarEventRow {
    id: number;
    owner_type: string | null;
    owner_id: number | null;
    title: string;
    starts_at: string | null;
    timezone: string;
    type: string | null;
    notes: string | null;
    url: string | null;
    reminder_minutes: number | null;
    reminder_sent_at: string | null;
}

interface Props {
    events: { data: CalendarEventRow[] };
    filters: { past: boolean };
    options: { types: string[]; owner_types: string[] };
}

type FormState =
    | { mode: 'closed' }
    | { mode: 'create' }
    | { mode: 'edit'; event: CalendarEventRow };

/**
 * Render a UTC ISO string as a `datetime-local` value (YYYY-MM-DDTHH:mm) in the
 * event's original IANA timezone, so editing round-trips the wall-clock time
 * the user first entered rather than their browser's zone.
 */
function toLocalInputValue(isoUtc: string | null, timezone: string): string {
    if (isoUtc === null) {
        return '';
    }
    const date = new Date(isoUtc);
    const parts = new Intl.DateTimeFormat('en-CA', {
        timeZone: timezone,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    }).formatToParts(date);

    const lookup = (type: string) => parts.find((part) => part.type === type)?.value ?? '';
    return `${lookup('year')}-${lookup('month')}-${lookup('day')}T${lookup('hour')}:${lookup('minute')}`;
}

function formatStart(isoUtc: string | null, timezone: string): string {
    if (isoUtc === null) {
        return '';
    }
    return new Intl.DateTimeFormat(undefined, {
        timeZone: timezone,
        weekday: 'short',
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        timeZoneName: 'short',
    }).format(new Date(isoUtc));
}

export default function CalendarIndexPage({ events, filters, options }: Props) {
    const [formState, setFormState] = useState<FormState>({ mode: 'closed' });
    const [pendingDelete, setPendingDelete] = useState<CalendarEventRow | null>(null);

    const rows = useMemo(() => events.data, [events.data]);

    function closeForm() {
        setFormState({ mode: 'closed' });
    }

    function confirmDelete() {
        if (pendingDelete === null) {
            return;
        }
        router.delete(`/calendar/${pendingDelete.id}`, {
            preserveScroll: true,
            onSuccess: () => setPendingDelete(null),
        });
    }

    function togglePast() {
        router.get('/calendar', filters.past ? {} : { past: 1 }, {
            preserveScroll: true,
            preserveState: true,
        });
    }

    return (
        <AuthenticatedLayout>
            <Head title="Calendar" />
            <div className="flex flex-col gap-6">
                <PageHeader
                    title="Calendar"
                    subtitle={
                        rows.length === 0
                            ? filters.past
                                ? 'No events on record.'
                                : 'Nothing scheduled ahead.'
                            : `${rows.length} ${rows.length === 1 ? 'event' : 'events'}${filters.past ? '' : ' coming up'}.`
                    }
                    actions={
                        <>
                            <Button type="button" variant="outline" onClick={togglePast}>
                                {filters.past ? 'Upcoming only' : 'Show past'}
                            </Button>
                            <Button type="button" onClick={() => setFormState({ mode: 'create' })}>
                                New event
                            </Button>
                        </>
                    }
                />

                {rows.length === 0 ? (
                    <EmptyState>
                        Add interviews, deadlines, and reminders. Subscribe to the feed from settings to see them in your own
                        calendar app.
                    </EmptyState>
                ) : (
                    <ul role="list" className="divide-y divide-border border-y border-border">
                        {rows.map((event) => (
                            <li key={event.id} className="flex flex-col gap-3 py-4 sm:flex-row sm:items-start sm:justify-between sm:gap-6">
                                <div className="flex min-w-0 flex-col gap-2">
                                    <div className="flex flex-col gap-0.5">
                                        <h2 className="text-base font-semibold text-balance">{event.title}</h2>
                                        <p className="text-base tabular-nums text-muted-foreground sm:text-sm">
                                            {formatStart(event.starts_at, event.timezone)}
                                        </p>
                                    </div>
                                    <div className="flex flex-wrap items-center gap-2">
                                        {event.type ? <Badge variant="outline">{humanizeType(event.type)}</Badge> : null}
                                        {event.owner_type ? (
                                            <Badge variant="secondary" className="tabular-nums">
                                                {humanizeType(event.owner_type)} #{event.owner_id}
                                            </Badge>
                                        ) : null}
                                        {event.reminder_minutes !== null ? (
                                            <Badge variant="outline" className="tabular-nums">
                                                Reminder {event.reminder_minutes}m
                                            </Badge>
                                        ) : null}
                                    </div>
                                    {event.url ? (
                                        <a
                                            href={event.url}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="block truncate text-base text-primary hover:underline sm:text-sm"
                                        >
                                            {event.url}
                                        </a>
                                    ) : null}
                                    {event.notes ? (
                                        <p className="text-base break-words whitespace-pre-wrap text-pretty text-muted-foreground sm:text-sm">
                                            {event.notes}
                                        </p>
                                    ) : null}
                                </div>
                                <div className="flex shrink-0 items-center gap-2">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={() => setFormState({ mode: 'edit', event })}
                                    >
                                        Edit
                                    </Button>
                                    <Button type="button" variant="ghost" size="sm" onClick={() => setPendingDelete(event)}>
                                        Delete
                                    </Button>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </div>

            <Dialog
                open={formState.mode !== 'closed'}
                onOpenChange={(open) => {
                    if (!open) {
                        closeForm();
                    }
                }}
            >
                <DialogContent className="max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>{formState.mode === 'edit' ? 'Edit event' : 'New event'}</DialogTitle>
                        <DialogDescription>
                            Times are stored in UTC and shown back in the timezone you enter. The reminder fires that many minutes
                            before the event.
                        </DialogDescription>
                    </DialogHeader>
                    {formState.mode !== 'closed' ? (
                        <CalendarEventForm
                            mode={formState.mode === 'edit' ? 'edit' : 'create'}
                            types={options.types}
                            ownerTypes={options.owner_types}
                            initial={
                                formState.mode === 'edit'
                                    ? {
                                          title: formState.event.title,
                                          starts_at: toLocalInputValue(formState.event.starts_at, formState.event.timezone),
                                          timezone: formState.event.timezone,
                                          type: formState.event.type ?? 'interview',
                                          owner_type: formState.event.owner_type ?? '',
                                          owner_id: formState.event.owner_id !== null ? String(formState.event.owner_id) : '',
                                          url: formState.event.url ?? '',
                                          notes: formState.event.notes ?? '',
                                          reminder_minutes:
                                              formState.event.reminder_minutes !== null
                                                  ? String(formState.event.reminder_minutes)
                                                  : '',
                                      }
                                    : {}
                            }
                            action={formState.mode === 'edit' ? `/calendar/${formState.event.id}` : '/calendar'}
                            method={formState.mode === 'edit' ? 'put' : 'post'}
                            onCancel={closeForm}
                            onSuccess={closeForm}
                        />
                    ) : null}
                </DialogContent>
            </Dialog>

            <Dialog
                open={pendingDelete !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setPendingDelete(null);
                    }
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Delete event</DialogTitle>
                        <DialogDescription>
                            {pendingDelete ? `Delete "${pendingDelete.title}"? This cannot be undone.` : ''}
                        </DialogDescription>
                    </DialogHeader>
                    <div className="flex items-center justify-end gap-3">
                        <Button type="button" variant="ghost" onClick={() => setPendingDelete(null)}>
                            Cancel
                        </Button>
                        <Button type="button" variant="destructive" onClick={confirmDelete}>
                            Delete
                        </Button>
                    </div>
                </DialogContent>
            </Dialog>
        </AuthenticatedLayout>
    );
}
