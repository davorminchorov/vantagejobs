import { Head, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Button } from '@/components/ui/button';
import { DataTableScroll } from '@/components/ui/data-table-scroll';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { SectionLabel } from '@/components/ui/section-label';
import { EmptyState } from '@/components/ui/empty-state';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import PageHeader from '@/components/PageHeader';

interface Source {
    id: number;
    type: string;
    name: string;
    enabled: boolean;
}

interface MatrixCell {
    source_id: number;
    source_type: string;
    field: string;
    n: number;
    p50: number | null;
    n_corrections: number;
}

interface RankedCell {
    source_id: number;
    source_type: string;
    field: string;
    p50: number;
    n: number;
    score: number;
}

interface CorrectionsCell {
    source_id: number;
    source_type: string;
    field: string;
    n_corrections: number;
}

interface RecentCorrection {
    id: number;
    created_at: string;
    field: string;
    source_id: number | null;
    source_type: string;
    listing_id: number;
    listing_role_title: string | null;
    old_value: unknown;
    new_value: unknown;
}

interface Thresholds {
    red_below: number;
    green_at_or_above: number;
}

interface Props {
    has_rollup_data: boolean;
    window_days: number;
    fields: string[];
    sources: Source[];
    matrix: MatrixCell[];
    lowest_confidence: RankedCell[];
    top_corrections: CorrectionsCell[];
    recent_corrections: RecentCorrection[];
    thresholds: Thresholds;
}

const FIELD_LABELS: Record<string, string> = {
    company_name: 'Company name',
    role_title: 'Role title',
    discipline: 'Discipline',
    seniority: 'Seniority',
    location: 'Location',
    region: 'Region',
    remote: 'Remote',
    compensation: 'Compensation',
    stack_tags: 'Stack tags',
    employment_type: 'Employment type',
    visa_sponsorship: 'Visa sponsorship',
};

function fieldLabel(field: string): string {
    if (FIELD_LABELS[field]) {
        return FIELD_LABELS[field];
    }
    const spaced = field.replace(/_/g, ' ');
    return spaced.charAt(0).toUpperCase() + spaced.slice(1);
}

function confidenceTextClass(p50: number | null, thresholds: Thresholds): string {
    if (p50 === null) {
        return 'text-muted-foreground';
    }
    if (p50 < thresholds.red_below) {
        return 'text-rose-700 dark:text-rose-300';
    }
    if (p50 < thresholds.green_at_or_above) {
        return 'text-amber-800 dark:text-amber-300';
    }
    return 'text-emerald-700 dark:text-emerald-300';
}

function summarizeValue(value: unknown): string {
    if (value === null || value === undefined) {
        return '–';
    }
    if (typeof value === 'string') {
        return value;
    }
    if (typeof value === 'number' || typeof value === 'boolean') {
        return String(value);
    }
    if (typeof value === 'object') {
        try {
            return JSON.stringify(value);
        } catch {
            return '[object]';
        }
    }
    return String(value);
}

export default function AdminParserIndex({
    has_rollup_data,
    window_days,
    fields,
    sources,
    matrix,
    lowest_confidence,
    top_corrections,
    recent_corrections,
    thresholds,
}: Props) {
    const [showConfirm, setShowConfirm] = useState(false);
    const [confirmText, setConfirmText] = useState('');

    const sourceNameById = useMemo(() => {
        const map: Record<number, string> = {};
        for (const source of sources) {
            map[source.id] = source.name;
        }
        return map;
    }, [sources]);

    const cellByKey = useMemo(() => {
        const map: Record<string, MatrixCell> = {};
        for (const c of matrix) {
            map[`${c.source_id}:${c.field}`] = c;
        }
        return map;
    }, [matrix]);

    function discoverLinkFor(sourceType: string): string {
        const params = new URLSearchParams({ status: 'new', source: sourceType });
        return `/discover?${params.toString()}`;
    }

    function runRollup() {
        router.post('/admin/parser/rollup', {}, { preserveScroll: true });
    }

    function submitReparseAll() {
        router.post(
            '/admin/parser/reparse-all',
            { confirm: confirmText },
            {
                preserveScroll: true,
                onFinish: () => {
                    setShowConfirm(false);
                    setConfirmText('');
                },
            },
        );
    }

    return (
        <AuthenticatedLayout>
            <Head title="Parser stats" />
            <div className="flex flex-col gap-8">
                <PageHeader
                    title="Parser stats"
                    subtitle={
                        <>
                            Per-source confidence and corrections, last <span className="tabular-nums">{window_days}</span> days. Reads
                            roll-up tables only. Refresh once the daily 02:30 UTC job has run.
                        </>
                    }
                    actions={
                        <>
                            <Button type="button" variant="outline" onClick={runRollup}>
                                Refresh rollup
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                className="text-rose-700 dark:text-rose-300"
                                onClick={() => setShowConfirm(true)}
                            >
                                Re-parse all
                            </Button>
                        </>
                    }
                />

                {!has_rollup_data && (
                    <EmptyState
                        action={
                            <Button type="button" onClick={runRollup}>
                                Run rollup now
                            </Button>
                        }
                    >
                        <p className="text-foreground font-medium">No roll-up data yet</p>
                        <p>
                            Roll-up rows are missing for the last <span className="tabular-nums">{window_days}</span> days. This is normal
                            on a fresh install. The scheduled job runs at 02:30 UTC.
                        </p>
                    </EmptyState>
                )}

                {has_rollup_data && (
                    <>
                        <section className="flex flex-col gap-3">
                            <SectionLabel>Median confidence by source × field</SectionLabel>
                            <DataTableScroll>
                                <table className="min-w-full text-base sm:text-sm">
                                    <thead>
                                        <tr className="border-border border-b text-left">
                                            <th className="bg-background text-muted-foreground sticky left-0 z-10 px-3 py-2 text-sm font-medium whitespace-nowrap">
                                                Source
                                            </th>
                                            {fields.map((field) => (
                                                <th
                                                    key={field}
                                                    className="text-muted-foreground px-3 py-2 text-right text-sm font-medium whitespace-nowrap"
                                                    title={field}
                                                >
                                                    {fieldLabel(field)}
                                                </th>
                                            ))}
                                        </tr>
                                    </thead>
                                    <tbody className="divide-border divide-y">
                                        {sources.map((source) => (
                                            <tr key={source.id}>
                                                <td className="bg-background sticky left-0 z-10 px-3 py-2 font-medium whitespace-nowrap">
                                                    {source.name}
                                                </td>
                                                {fields.map((field) => {
                                                    const cell = cellByKey[`${source.id}:${field}`];
                                                    const p50 = cell?.p50 ?? null;
                                                    return (
                                                        <td key={field} className="px-3 py-2 text-right">
                                                            <a
                                                                href={discoverLinkFor(source.type)}
                                                                className={`text-sm tabular-nums hover:underline ${confidenceTextClass(p50, thresholds)}`}
                                                                title={
                                                                    cell
                                                                        ? `n=${cell.n}  p50=${p50?.toFixed(2) ?? '–'}  corrections=${cell.n_corrections}`
                                                                        : 'no data'
                                                                }
                                                            >
                                                                {p50 === null ? '–' : p50.toFixed(2)}
                                                            </a>
                                                        </td>
                                                    );
                                                })}
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </DataTableScroll>
                        </section>

                        <div className="border-border grid grid-cols-1 gap-8 border-t pt-6 lg:grid-cols-2">
                            <section className="flex min-w-0 flex-col gap-3">
                                <SectionLabel>Top 10 lowest-confidence (weighted)</SectionLabel>
                                {lowest_confidence.length === 0 ? (
                                    <p className="text-muted-foreground text-base sm:text-sm">No data.</p>
                                ) : (
                                    <ul role="list" className="divide-border divide-y">
                                        {lowest_confidence.map((row) => (
                                            <li
                                                key={`${row.source_id}:${row.field}`}
                                                className="flex items-center justify-between gap-3 py-3 text-base sm:text-sm"
                                            >
                                                <div className="min-w-0">
                                                    <a
                                                        href={discoverLinkFor(row.source_type)}
                                                        className="text-primary font-medium hover:underline"
                                                    >
                                                        {sourceNameById[row.source_id] ?? row.source_type}
                                                    </a>
                                                    <span className="text-muted-foreground"> · </span>
                                                    <span>{fieldLabel(row.field)}</span>
                                                </div>
                                                <div className="text-muted-foreground shrink-0 text-right text-sm">
                                                    p50 <span className="tabular-nums">{row.p50.toFixed(2)}</span> · n{' '}
                                                    <span className="tabular-nums">{row.n}</span>
                                                </div>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </section>

                            <section className="flex min-w-0 flex-col gap-3">
                                <SectionLabel>Corrections by source × field</SectionLabel>
                                {top_corrections.length === 0 ? (
                                    <p className="text-muted-foreground text-base sm:text-sm">No corrections in the window.</p>
                                ) : (
                                    <ul role="list" className="divide-border divide-y">
                                        {top_corrections.map((row) => (
                                            <li
                                                key={`${row.source_id}:${row.field}`}
                                                className="flex items-center justify-between gap-3 py-3 text-base sm:text-sm"
                                            >
                                                <div className="min-w-0">
                                                    <a
                                                        href={discoverLinkFor(row.source_type)}
                                                        className="text-primary font-medium hover:underline"
                                                    >
                                                        {sourceNameById[row.source_id] ?? row.source_type}
                                                    </a>
                                                    <span className="text-muted-foreground"> · </span>
                                                    <span>{fieldLabel(row.field)}</span>
                                                </div>
                                                <div className="shrink-0 text-right tabular-nums">{row.n_corrections}</div>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </section>
                        </div>

                        <section className="border-border flex flex-col gap-3 border-t pt-6">
                            <SectionLabel>Recent corrections</SectionLabel>
                            {recent_corrections.length === 0 ? (
                                <p className="text-muted-foreground text-base sm:text-sm">No corrections recorded yet.</p>
                            ) : (
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Time</TableHead>
                                            <TableHead>Source</TableHead>
                                            <TableHead>Listing</TableHead>
                                            <TableHead>Field</TableHead>
                                            <TableHead>Old → New</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {recent_corrections.map((c) => (
                                            <TableRow key={c.id}>
                                                <TableCell className="text-muted-foreground whitespace-nowrap tabular-nums">
                                                    {c.created_at}
                                                </TableCell>
                                                <TableCell>
                                                    {(c.source_id !== null ? sourceNameById[c.source_id] : undefined) ?? c.source_type}
                                                </TableCell>
                                                <TableCell>
                                                    <a href={discoverLinkFor(c.source_type)} className="text-primary hover:underline">
                                                        {c.listing_role_title ?? (
                                                            <span className="font-mono tabular-nums">{`#${c.listing_id}`}</span>
                                                        )}
                                                    </a>
                                                </TableCell>
                                                <TableCell>{fieldLabel(c.field)}</TableCell>
                                                <TableCell className="font-mono">
                                                    <span className="text-muted-foreground">{summarizeValue(c.old_value)}</span>
                                                    <span className="text-muted-foreground px-1">→</span>
                                                    <span>{summarizeValue(c.new_value)}</span>
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            )}
                        </section>
                    </>
                )}
            </div>

            <Dialog open={showConfirm} onOpenChange={setShowConfirm}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Re-parse every listing?</DialogTitle>
                        <DialogDescription>
                            This re-runs the parser against every aggregator listing for this tenant. Type{' '}
                            <code className="bg-muted rounded-md px-1 font-mono">YES</code> below to confirm.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="flex flex-col gap-2">
                        <Label htmlFor="reparse-confirm">Confirmation</Label>
                        <Input
                            id="reparse-confirm"
                            name="confirm"
                            value={confirmText}
                            onChange={(e) => setConfirmText(e.target.value)}
                            placeholder="Type YES to confirm"
                            autoFocus
                        />
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => setShowConfirm(false)}>
                            Cancel
                        </Button>
                        <Button type="button" variant="destructive" disabled={confirmText !== 'YES'} onClick={submitReparseAll}>
                            Re-parse all
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AuthenticatedLayout>
    );
}
