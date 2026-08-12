import { Head, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import CalendarEventForm, { humanizeType } from '@/components/calendar/CalendarEventForm';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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
            <div className="space-y-6">
                <header className="flex items-end justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight text-foreground">Calendar</h1>
                        <p className="text-sm text-muted-foreground">
                            {rows.length === 0
                                ? filters.past
                                    ? 'No events on record.'
                                    : 'Nothing scheduled ahead.'
                                : `${rows.length} ${rows.length === 1 ? 'event' : 'events'}${filters.past ? '' : ' coming up'}.`}
                        </p>
                    </div>
                    <div className="flex items-center gap-2">
                        <Button variant="ghost" onClick={togglePast}>
                            {filters.past ? 'Upcoming only' : 'Show past'}
                        </Button>
                        <Button onClick={() => setFormState({ mode: 'create' })}>New event</Button>
                    </div>
                </header>

                {rows.length === 0 ? (
                    <EmptyState>
                        Add interviews, deadlines, and reminders. Subscribe to the feed from settings to see them in your own
                        calendar app.
                    </EmptyState>
                ) : (
                    <div className="grid gap-4 sm:grid-cols-2">
                        {rows.map((event) => (
                            <Card key={event.id} className="rounded-2xl border-border">
                                <CardHeader className="flex flex-row items-start justify-between gap-2 space-y-0">
                                    <div className="space-y-1">
                                        <CardTitle className="text-base">{event.title}</CardTitle>
                                        <p className="font-mono text-xs tabular-nums text-muted-foreground">
                                            {formatStart(event.starts_at, event.timezone)}
                                        </p>
                                    </div>
                                    <div className="flex shrink-0 items-center gap-2">
                                        <button
                                            type="button"
                                            onClick={() => setFormState({ mode: 'edit', event })}
                                            className="text-xs font-medium text-muted-foreground hover:text-foreground"
                                        >
                                            Edit
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => setPendingDelete(event)}
                                            className="text-xs font-medium text-destructive hover:underline"
                                        >
                                            Delete
                                        </button>
                                    </div>
                                </CardHeader>
                                <CardContent className="space-y-2">
                                    <div className="flex flex-wrap items-center gap-2">
                                        {event.type ? (
                                            <Badge variant="outline" className="font-mono">
                                                {humanizeType(event.type)}
                                            </Badge>
                                        ) : null}
                                        {event.owner_type ? (
                                            <Badge variant="secondary" className="font-mono">
                                                {humanizeType(event.owner_type)} #{event.owner_id}
                                            </Badge>
                                        ) : null}
                                        {event.reminder_minutes !== null ? (
                                            <Badge variant="outline" className="font-mono tabular-nums">
                                                Reminder {event.reminder_minutes}m
                                            </Badge>
                                        ) : null}
                                    </div>
                                    {event.url ? (
                                        <a
                                            href={event.url}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="block truncate text-xs text-primary hover:underline"
                                        >
                                            {event.url}
                                        </a>
                                    ) : null}
                                    {event.notes ? (
                                        <p className="whitespace-pre-wrap break-words text-sm text-muted-foreground">{event.notes}</p>
                                    ) : null}
                                </CardContent>
                            </Card>
                        ))}
                    </div>
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
                        <Button variant="ghost" onClick={() => setPendingDelete(null)}>
                            Cancel
                        </Button>
                        <Button variant="destructive" onClick={confirmDelete}>
                            Delete
                        </Button>
                    </div>
                </DialogContent>
            </Dialog>
        </AuthenticatedLayout>
    );
}
