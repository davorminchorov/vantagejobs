import { Link } from '@inertiajs/react';

import { humanizeType } from '@/components/calendar/CalendarEventForm';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

import { formatEventStart, formatEventTime, safeHttpUrl } from './helpers';
import type { AgendaEvent } from './types';

interface EventRowProps {
    event: AgendaEvent;
    /** Show the time only (the day is already in a group heading) instead of the full date. */
    timeOnly?: boolean;
    /** Show who owns the event. Hidden inside an application's own events list. */
    showOwner?: boolean;
    onOpenApplication?: (roleId: number) => void;
    onEdit: (event: AgendaEvent) => void;
    onDelete: (event: AgendaEvent) => void;
}

export function EventRow({ event, timeOnly = false, showOwner = true, onOpenApplication, onEdit, onDelete }: EventRowProps) {
    const url = safeHttpUrl(event.url);
    const owner = showOwner ? event.owner : null;

    return (
        <li className="flex flex-col gap-2 py-3 sm:flex-row sm:items-start sm:justify-between sm:gap-4">
            <div className="flex min-w-0 flex-col gap-1">
                <span className="text-base font-medium text-pretty sm:text-sm">{event.title}</span>
                <span className="text-base text-muted-foreground tabular-nums sm:text-sm">
                    {timeOnly ? formatEventTime(event.starts_at, event.timezone) : formatEventStart(event.starts_at, event.timezone)}
                </span>
                <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                    <Badge variant="outline">{humanizeType(event.type)}</Badge>
                    {owner ? (
                        owner.role_id !== null && onOpenApplication ? (
                            <button
                                type="button"
                                onClick={() => onOpenApplication(owner.role_id as number)}
                                className="text-base text-primary hover:underline sm:text-sm"
                            >
                                {owner.label}
                            </button>
                        ) : owner.href ? (
                            <Link href={owner.href} className="text-base text-primary hover:underline sm:text-sm">
                                {owner.label}
                            </Link>
                        ) : (
                            <span className="text-base text-muted-foreground sm:text-sm">{owner.label}</span>
                        )
                    ) : null}
                    {event.reminder_minutes !== null ? (
                        <span className="text-sm text-muted-foreground tabular-nums sm:text-xs">
                            Reminder {event.reminder_minutes} min before
                        </span>
                    ) : null}
                </div>
                {url ? (
                    <a
                        href={url}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="block truncate text-base text-primary hover:underline sm:text-sm"
                    >
                        {url}
                    </a>
                ) : null}
                {event.notes ? (
                    <p className="text-base break-words whitespace-pre-wrap text-pretty text-muted-foreground sm:text-sm">{event.notes}</p>
                ) : null}
            </div>
            <div className="flex shrink-0 items-center gap-2">
                <Button type="button" variant="outline" size="sm" onClick={() => onEdit(event)}>
                    Edit
                </Button>
                <Button type="button" variant="ghost" size="sm" onClick={() => onDelete(event)}>
                    Delete
                </Button>
            </div>
        </li>
    );
}
