import type { AgendaEvent, ApplicationRow, ApplicationsFilters, AttentionReason, StatusOption } from './types';

export type BadgeVariant = 'default' | 'success' | 'warning' | 'destructive' | 'secondary' | 'outline';

/** Stage badge colours. Labels and terminal flags come from the `statuses` prop, never from here. */
export const STATUS_VARIANT: Record<string, BadgeVariant> = {
    researching: 'secondary',
    applied: 'default',
    screen: 'warning',
    onsite: 'warning',
    offer: 'success',
    rejected: 'destructive',
    withdrawn: 'outline',
};

export function statusLabel(statuses: StatusOption[], value: string | null): string {
    if (value === null) {
        return 'Not added';
    }
    return statuses.find((status) => status.value === value)?.label ?? value;
}

export function isTerminal(statuses: StatusOption[], value: string): boolean {
    return statuses.find((status) => status.value === value)?.terminal ?? false;
}

/** Only http and https links are ever rendered as hrefs. */
export function safeHttpUrl(url: string | null | undefined): string | null {
    if (!url) {
        return null;
    }
    try {
        const parsed = new URL(url);
        return parsed.protocol === 'http:' || parsed.protocol === 'https:' ? parsed.toString() : null;
    } catch {
        return null;
    }
}

/** Query string for /applications. Defaults are omitted, `past` only applies to the upcoming view. */
export function buildQuery(filters: ApplicationsFilters, patch: Partial<ApplicationsFilters> = {}): Record<string, string | number> {
    const next = { ...filters, ...patch };
    const query: Record<string, string | number> = {};
    if (next.view !== 'all') {
        query.view = next.view;
    }
    if (next.stage !== 'all') {
        query.stage = next.stage;
    }
    if (next.role !== null) {
        query.role = next.role;
    }
    if (next.view === 'upcoming' && next.past) {
        query.past = 1;
    }
    return query;
}

function parseCalendarDate(value: string): Date | null {
    const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(value);
    if (!match) {
        return null;
    }
    return new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]));
}

/** Renders a YYYY-MM-DD as a calendar date without shifting it by the viewer's timezone. */
export function formatCalendarDate(value: string | null | undefined): string {
    if (!value) {
        return '';
    }
    const date = parseCalendarDate(value);
    return date ? date.toLocaleDateString(undefined, { dateStyle: 'medium' }) : value;
}

/** Date part of an ISO timestamp, e.g. "Nov 12, 2026". Empty string for missing input. */
export function formatIsoDate(iso: string | null | undefined): string {
    if (!iso) {
        return '';
    }
    const date = new Date(iso);
    return Number.isNaN(date.getTime()) ? '' : date.toLocaleDateString(undefined, { dateStyle: 'medium' });
}

/** "Thu, Nov 12, 2:00 PM EST" in the zone the event was entered in. */
export function formatEventStart(isoUtc: string, timezone: string): string {
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

/** "Nov 12, 2:00 PM" for compact rows. */
export function formatEventShort(isoUtc: string, timezone: string): string {
    return new Intl.DateTimeFormat(undefined, {
        timeZone: timezone,
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    }).format(new Date(isoUtc));
}

/** "2:00 PM EST" in the event's zone. */
export function formatEventTime(isoUtc: string, timezone: string): string {
    return new Intl.DateTimeFormat(undefined, {
        timeZone: timezone,
        hour: 'numeric',
        minute: '2-digit',
        timeZoneName: 'short',
    }).format(new Date(isoUtc));
}

/**
 * A UTC ISO string as a `datetime-local` value (YYYY-MM-DDTHH:mm) in the event's
 * IANA timezone, so editing round-trips the wall-clock time that was entered.
 */
export function toLocalInputValue(isoUtc: string, timezone: string): string {
    const parts = new Intl.DateTimeFormat('en-CA', {
        timeZone: timezone,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    }).formatToParts(new Date(isoUtc));

    const lookup = (type: string) => parts.find((part) => part.type === type)?.value ?? '';
    const hour = lookup('hour') === '24' ? '00' : lookup('hour');
    return `${lookup('year')}-${lookup('month')}-${lookup('day')}T${hour}:${lookup('minute')}`;
}

function dayKey(isoUtc: string, timezone: string): string {
    return new Intl.DateTimeFormat('en-CA', {
        timeZone: timezone,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).format(new Date(isoUtc));
}

function localDayKey(date: Date): string {
    const pad = (n: number) => String(n).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

export interface AgendaDay {
    key: string;
    label: string;
    events: AgendaEvent[];
}

/** Groups events by the calendar day shown in each event's own timezone. Keeps input order. */
export function groupAgendaByDay(events: AgendaEvent[], now: Date = new Date()): AgendaDay[] {
    const today = localDayKey(now);
    const tomorrow = localDayKey(new Date(now.getFullYear(), now.getMonth(), now.getDate() + 1));
    const days: AgendaDay[] = [];

    for (const event of events) {
        const key = dayKey(event.starts_at, event.timezone);
        let day = days.find((candidate) => candidate.key === key);
        if (!day) {
            const date = parseCalendarDate(key);
            const label =
                key === today
                    ? 'Today'
                    : key === tomorrow
                      ? 'Tomorrow'
                      : date
                        ? date.toLocaleDateString(undefined, { weekday: 'long', month: 'short', day: 'numeric', year: 'numeric' })
                        : key;
            day = { key, label, events: [] };
            days.push(day);
        }
        day.events.push(event);
    }

    return days;
}

/** "Next step overdue: Email recruiter, due Nov 3, 2026" / "Quiet for 12 days". */
export function attentionText(
    reason: AttentionReason,
    row: Pick<ApplicationRow, 'next_action' | 'next_action_date' | 'days_quiet'>,
): string {
    if (reason === 'next_step_overdue') {
        const due = formatCalendarDate(row.next_action_date);
        const parts = [row.next_action, due ? `due ${due}` : null].filter(Boolean);
        return parts.length > 0 ? `Next step overdue: ${parts.join(', ')}` : 'Next step overdue';
    }
    return row.days_quiet !== null ? `Quiet for ${row.days_quiet} ${row.days_quiet === 1 ? 'day' : 'days'}` : 'Quiet';
}

/** The single context line under an application row. */
export function rowContext(row: ApplicationRow): string {
    if (row.attention !== null) {
        return attentionText(row.attention, row);
    }
    const due = formatCalendarDate(row.next_action_date);
    if (row.next_action) {
        return due ? `Next: ${row.next_action}, ${due}` : `Next: ${row.next_action}`;
    }
    if (due) {
        return `Next step due ${due}`;
    }
    const applied = formatIsoDate(row.applied_at);
    return applied ? `Applied ${applied}` : 'No next step set';
}
