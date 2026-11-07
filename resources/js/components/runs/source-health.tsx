import { ChevronRight } from 'lucide-react';
import { useState } from 'react';

import { kindLabel } from '@/components/runs/run-status';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { RelativeDate } from '@/lib/dates';

export interface HealthEntry {
    kind: string;
    value: string;
    label: string;
    reason: string;
    last_run_at: string | null;
    consecutive_failures: number;
}

interface SourceHealthProps {
    health: HealthEntry[];
    onSelectSource: (value: string) => void;
}

/**
 * Collapsed-by-default list of sources that need attention. Selecting a
 * source filters the runs table to it.
 */
export function SourceHealth({ health, onSelectSource }: SourceHealthProps) {
    const [open, setOpen] = useState(false);

    if (health.length === 0) {
        return <p className="text-base text-muted-foreground sm:text-sm">All sources healthy.</p>;
    }

    return (
        <section className="flex flex-col gap-2">
            <button
                type="button"
                aria-expanded={open}
                onClick={() => setOpen(!open)}
                className="flex items-center gap-1.5 self-start rounded-md text-base font-medium focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring sm:text-sm"
            >
                <ChevronRight
                    className={'size-4 text-muted-foreground transition-transform ' + (open ? 'rotate-90' : '')}
                    aria-hidden="true"
                />
                Needs attention ({health.length})
            </button>
            {open && (
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Source</TableHead>
                            <TableHead>Kind</TableHead>
                            <TableHead>Reason</TableHead>
                            <TableHead>Last run</TableHead>
                            <TableHead className="text-right">Consecutive failures</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {health.map((h) => (
                            <TableRow key={`${h.kind}-${h.value}`}>
                                <TableCell className="whitespace-nowrap">
                                    <button
                                        type="button"
                                        onClick={() => onSelectSource(h.value)}
                                        className="font-medium hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                                    >
                                        {h.label}
                                    </button>
                                </TableCell>
                                <TableCell className="whitespace-nowrap text-muted-foreground">{kindLabel(h.kind)}</TableCell>
                                <TableCell className="max-w-72 truncate text-muted-foreground" title={h.reason}>
                                    {h.reason}
                                </TableCell>
                                <TableCell className="whitespace-nowrap text-muted-foreground tabular-nums">
                                    {h.last_run_at ? <RelativeDate iso={h.last_run_at} /> : 'Never'}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">{h.consecutive_failures}</TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            )}
        </section>
    );
}
