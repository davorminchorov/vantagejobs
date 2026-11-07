import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

import { STATUS_VARIANT, formatEventShort, isTerminal, rowContext, statusLabel } from './helpers';
import type { ApplicationRow, StatusOption } from './types';

interface ApplicationListProps {
    applications: ApplicationRow[];
    statuses: StatusOption[];
    selectedId: number | null;
    /** True when the stage filter is a closed stage, so the Closed group must not start collapsed. */
    forceClosedOpen: boolean;
    onSelect: (id: number) => void;
}

/** Applications grouped as Needs attention, In progress and Closed (collapsed by default). */
export function ApplicationList({ applications, statuses, selectedId, forceClosedOpen, onSelect }: ApplicationListProps) {
    const [closedOpen, setClosedOpen] = useState(false);

    const closed = applications.filter((row) => isTerminal(statuses, row.status));
    const open = applications.filter((row) => !isTerminal(statuses, row.status));
    const attention = open.filter((row) => row.attention !== null);
    const inProgress = open.filter((row) => row.attention === null);
    const closedExpanded = closedOpen || forceClosedOpen || closed.some((row) => row.id === selectedId);

    return (
        <div className="flex flex-col gap-5">
            {attention.length > 0 ? (
                <Group title="Needs attention" count={attention.length}>
                    {attention.map((row) => (
                        <Row key={row.id} row={row} statuses={statuses} selected={row.id === selectedId} onSelect={onSelect} />
                    ))}
                </Group>
            ) : null}
            {inProgress.length > 0 ? (
                <Group title="In progress" count={inProgress.length}>
                    {inProgress.map((row) => (
                        <Row key={row.id} row={row} statuses={statuses} selected={row.id === selectedId} onSelect={onSelect} />
                    ))}
                </Group>
            ) : null}
            {closed.length > 0 ? (
                <div className="flex flex-col gap-1">
                    <button
                        type="button"
                        onClick={() => setClosedOpen((value) => !value)}
                        aria-expanded={closedExpanded}
                        disabled={forceClosedOpen}
                        className="w-fit text-sm font-medium text-muted-foreground hover:text-foreground"
                    >
                        {closedExpanded ? 'Closed' : 'Show closed'} <span className="tabular-nums">({closed.length})</span>
                    </button>
                    {closedExpanded ? (
                        <ul role="list" className="flex flex-col gap-0.5">
                            {closed.map((row) => (
                                <Row key={row.id} row={row} statuses={statuses} selected={row.id === selectedId} onSelect={onSelect} />
                            ))}
                        </ul>
                    ) : null}
                </div>
            ) : null}
        </div>
    );
}

function Group({ title, count, children }: { title: string; count: number; children: React.ReactNode }) {
    return (
        <section className="flex flex-col gap-1">
            <h2 className="text-sm font-medium text-muted-foreground">
                {title} <span className="tabular-nums">({count})</span>
            </h2>
            <ul role="list" className="flex flex-col gap-0.5">
                {children}
            </ul>
        </section>
    );
}

interface RowProps {
    row: ApplicationRow;
    statuses: StatusOption[];
    selected: boolean;
    onSelect: (id: number) => void;
}

function Row({ row, statuses, selected, onSelect }: RowProps) {
    return (
        <li className={cn('relative rounded-lg px-3 py-2.5', selected ? 'bg-muted' : 'hover:bg-muted/50')}>
            <button
                type="button"
                onClick={() => onSelect(row.id)}
                aria-current={selected ? 'true' : undefined}
                className="flex w-full min-w-0 flex-col gap-1 text-left outline-none after:absolute after:inset-0 after:rounded-lg focus-visible:after:outline-2 focus-visible:after:outline-ring"
            >
                <span className="flex items-baseline gap-3">
                    <span className="min-w-0 flex-1 truncate text-base font-medium sm:text-sm">{row.title}</span>
                    <Badge variant={STATUS_VARIANT[row.status] ?? 'secondary'} className="shrink-0">
                        {statusLabel(statuses, row.status)}
                    </Badge>
                </span>
                <span className="truncate text-sm text-muted-foreground">{row.company?.name ?? 'Unknown company'}</span>
                <span
                    className={cn(
                        'truncate text-sm',
                        row.attention !== null ? 'font-medium text-amber-700 dark:text-amber-300' : 'text-muted-foreground',
                    )}
                >
                    {rowContext(row)}
                </span>
                {row.next_event ? (
                    <span className="truncate text-sm text-muted-foreground tabular-nums">
                        {row.next_event.title}, {formatEventShort(row.next_event.starts_at, row.next_event.timezone)}
                    </span>
                ) : null}
            </button>
        </li>
    );
}
