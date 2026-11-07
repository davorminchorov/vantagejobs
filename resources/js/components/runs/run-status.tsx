import { Badge } from '@/components/ui/badge';

type BadgeVariant = 'success' | 'warning' | 'destructive' | 'secondary' | 'outline';

const STATUS_LABELS: Record<string, string> = {
    completed: 'Completed',
    not_modified: 'Not modified',
    partial: 'Partial',
    failed: 'Failed',
    error: 'Failed',
    running: 'Running',
};

const KIND_LABELS: Record<string, string> = {
    ats: 'ATS',
    aggregator: 'Aggregator',
};

const RUNNING_CLASS = 'bg-sky-50 text-sky-700 inset-ring-sky-600/20 dark:bg-sky-400/10 dark:text-sky-300 dark:inset-ring-sky-400/20';

function humanize(value: string): string {
    const spaced = value.replace(/_/g, ' ');
    return spaced.charAt(0).toUpperCase() + spaced.slice(1);
}

export function statusLabel(status: string): string {
    return STATUS_LABELS[status] ?? humanize(status);
}

export function kindLabel(kind: string): string {
    return KIND_LABELS[kind] ?? humanize(kind);
}

function statusVariant(status: string): BadgeVariant {
    switch (status) {
        case 'completed':
            return 'success';
        case 'partial':
            return 'warning';
        case 'failed':
        case 'error':
            return 'destructive';
        case 'running':
            return 'outline';
        default:
            return 'secondary';
    }
}

export function RunStatusBadge({ status }: { status: string }) {
    return (
        <Badge variant={statusVariant(status)} className={status === 'running' ? RUNNING_CLASS : undefined}>
            {statusLabel(status)}
        </Badge>
    );
}

export function formatDuration(seconds: number | null): string {
    if (seconds === null) {
        return '–';
    }
    if (seconds < 60) {
        return `${seconds}s`;
    }
    const m = Math.floor(seconds / 60);
    const s = seconds % 60;
    return s === 0 ? `${m}m` : `${m}m ${s}s`;
}
