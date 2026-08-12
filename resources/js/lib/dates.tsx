/**
 * Shared date formatting (PRD 8.8: relative display with the absolute value on hover).
 * All helpers accept an ISO string and return '—' for null/undefined/invalid input.
 */

const RELATIVE_UNITS: ReadonlyArray<{ unit: Intl.RelativeTimeFormatUnit; seconds: number }> = [
    { unit: 'year', seconds: 31_536_000 },
    { unit: 'month', seconds: 2_592_000 },
    { unit: 'week', seconds: 604_800 },
    { unit: 'day', seconds: 86_400 },
    { unit: 'hour', seconds: 3_600 },
    { unit: 'minute', seconds: 60 },
];

function parse(iso: string | null | undefined): Date | null {
    if (!iso) {
        return null;
    }
    const date = new Date(iso);
    return Number.isNaN(date.getTime()) ? null : date;
}

/** Medium date, e.g. "May 28, 2026". */
export function formatDate(iso: string | null | undefined): string {
    const date = parse(iso);
    return date ? date.toLocaleDateString(undefined, { dateStyle: 'medium' }) : '—';
}

/** Medium date + short time (no seconds), e.g. "May 28, 2026, 2:00 AM". */
export function formatDateTime(iso: string | null | undefined): string {
    const date = parse(iso);
    return date ? date.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '—';
}

/** Relative time, e.g. "2 hours ago" / "in 3 days" / "just now". */
export function formatRelative(iso: string | null | undefined): string {
    const date = parse(iso);
    if (!date) {
        return '—';
    }
    const deltaSeconds = (date.getTime() - Date.now()) / 1000;
    const magnitude = Math.abs(deltaSeconds);
    if (magnitude < 60) {
        return 'just now';
    }
    const formatter = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });
    for (const { unit, seconds } of RELATIVE_UNITS) {
        if (magnitude >= seconds) {
            return formatter.format(Math.trunc(deltaSeconds / seconds), unit);
        }
    }
    return 'just now';
}

interface RelativeDateProps {
    iso: string | null | undefined;
    className?: string;
}

/** Relative time with the absolute date-time available on hover. */
export function RelativeDate({ iso, className }: RelativeDateProps) {
    if (!parse(iso)) {
        return <span className={className}>—</span>;
    }
    return (
        <time dateTime={iso ?? undefined} title={formatDateTime(iso)} className={className}>
            {formatRelative(iso)}
        </time>
    );
}
