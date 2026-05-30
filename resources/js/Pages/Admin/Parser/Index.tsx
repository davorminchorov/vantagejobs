import { Head, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

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

function classifyConfidence(p50: number | null, thresholds: Thresholds): string {
    if (p50 === null) {
        return 'bg-muted text-muted-foreground';
    }
    if (p50 < thresholds.red_below) {
        return 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-200';
    }
    if (p50 < thresholds.green_at_or_above) {
        return 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-200';
    }
    return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-200';
}

function summarizeValue(value: unknown): string {
    if (value === null || value === undefined) {
        return '—';
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
            <div className="mx-auto max-w-7xl space-y-8 px-4 py-8 sm:px-6 lg:px-8">
                <header className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">Parser stats</h1>
                        <p className="text-sm text-muted-foreground">
                            Per-source confidence + corrections, last{' '}
                            <span className="font-mono tabular-nums">{window_days}</span> days. Reads
                            roll-up
                            tables only — refresh once the daily 02:30 UTC job has run.
                        </p>
                    </div>
                    <div className="flex shrink-0 items-center gap-2">
                        <Button variant="outline" onClick={runRollup}>
                            Refresh rollup
                        </Button>
                        <Button variant="destructive" onClick={() => setShowConfirm(true)}>
                            Re-parse all
                        </Button>
                    </div>
                </header>

                {!has_rollup_data && (
                    <Card className="rounded-2xl">
                        <CardHeader>
                            <CardTitle>No roll-up data yet</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <p className="text-sm text-muted-foreground">
                                Roll-up rows are missing for the last{' '}
                                <span className="font-mono tabular-nums">{window_days}</span> days. This is
                                normal on a fresh install — the scheduled job runs at 02:30 UTC.
                            </p>
                            <Button onClick={runRollup}>Run rollup now</Button>
                        </CardContent>
                    </Card>
                )}

                {has_rollup_data && (
                    <>
                        <Card className="rounded-2xl">
                            <CardHeader>
                                <CardTitle>Median confidence by source × field</CardTitle>
                            </CardHeader>
                            <CardContent className="overflow-x-auto">
                                <table className="min-w-full text-sm">
                                    <thead>
                                        <tr className="text-left">
                                            <th className="px-3 py-2 font-medium text-muted-foreground">Source</th>
                                            {fields.map((field) => (
                                                <th
                                                    key={field}
                                                    className="px-2 py-2 text-center font-medium text-muted-foreground"
                                                    title={field}
                                                >
                                                    {field}
                                                </th>
                                            ))}
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {sources.map((source) => (
                                            <tr key={source.id} className="border-t">
                                                <td className="whitespace-nowrap px-3 py-2 font-medium">
                                                    {source.name}
                                                </td>
                                                {fields.map((field) => {
                                                    const cell = cellByKey[`${source.id}:${field}`];
                                                    const p50 = cell?.p50 ?? null;
                                                    const klass = classifyConfidence(p50, thresholds);
                                                    return (
                                                        <td key={field} className="px-1 py-1 text-center">
                                                            <a
                                                                href={discoverLinkFor(source.type)}
                                                                className={`block rounded-md px-2 py-2 font-mono text-xs tabular-nums hover:ring-2 hover:ring-ring hover:ring-offset-1 ${klass}`}
                                                                title={
                                                                    cell
                                                                        ? `n=${cell.n}  p50=${p50?.toFixed(2) ?? '—'}  corrections=${cell.n_corrections}`
                                                                        : 'no data'
                                                                }
                                                            >
                                                                {p50 === null ? '—' : p50.toFixed(2)}
                                                            </a>
                                                        </td>
                                                    );
                                                })}
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </CardContent>
                        </Card>

                        <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                            <Card className="rounded-2xl">
                                <CardHeader>
                                    <CardTitle>Top 10 lowest-confidence (weighted)</CardTitle>
                                </CardHeader>
                                <CardContent className="p-0">
                                    {lowest_confidence.length === 0 ? (
                                        <p className="px-6 py-4 text-sm text-muted-foreground">
                                            No data.
                                        </p>
                                    ) : (
                                        <ul className="divide-y">
                                            {lowest_confidence.map((row) => (
                                                <li
                                                    key={`${row.source_id}:${row.field}`}
                                                    className="flex items-center justify-between gap-3 px-6 py-3 text-sm"
                                                >
                                                    <div>
                                                        <a
                                                            href={discoverLinkFor(row.source_type)}
                                                            className="font-medium text-teal-700 hover:underline dark:text-teal-300"
                                                        >
                                                            {row.source_type}
                                                        </a>
                                                        <span className="text-muted-foreground"> · </span>
                                                        <span>{row.field}</span>
                                                    </div>
                                                    <div className="text-right text-xs text-muted-foreground">
                                                        p50{' '}
                                                        <span className="font-mono tabular-nums">
                                                            {row.p50.toFixed(2)}
                                                        </span>{' '}
                                                        · n{' '}
                                                        <span className="font-mono tabular-nums">{row.n}</span>
                                                    </div>
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </CardContent>
                            </Card>

                            <Card className="rounded-2xl">
                                <CardHeader>
                                    <CardTitle>Corrections by source × field</CardTitle>
                                </CardHeader>
                                <CardContent className="p-0">
                                    {top_corrections.length === 0 ? (
                                        <p className="px-6 py-4 text-sm text-muted-foreground">
                                            No corrections in the window.
                                        </p>
                                    ) : (
                                        <ul className="divide-y">
                                            {top_corrections.map((row) => (
                                                <li
                                                    key={`${row.source_id}:${row.field}`}
                                                    className="flex items-center justify-between gap-3 px-6 py-3 text-sm"
                                                >
                                                    <div>
                                                        <a
                                                            href={discoverLinkFor(row.source_type)}
                                                            className="font-medium text-teal-700 hover:underline dark:text-teal-300"
                                                        >
                                                            {row.source_type}
                                                        </a>
                                                        <span className="text-muted-foreground"> · </span>
                                                        <span>{row.field}</span>
                                                    </div>
                                                    <div className="text-right font-mono text-xs tabular-nums">
                                                        {row.n_corrections}
                                                    </div>
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </CardContent>
                            </Card>
                        </div>

                        <Card className="rounded-2xl">
                            <CardHeader>
                                <CardTitle>Recent corrections</CardTitle>
                            </CardHeader>
                            <CardContent className="p-0">
                                {recent_corrections.length === 0 ? (
                                    <p className="px-6 py-4 text-sm text-muted-foreground">
                                        No corrections recorded yet.
                                    </p>
                                ) : (
                                    <table className="min-w-full text-sm">
                                        <thead>
                                            <tr className="text-left">
                                                <th className="px-4 py-2 font-medium text-muted-foreground">
                                                    Time
                                                </th>
                                                <th className="px-4 py-2 font-medium text-muted-foreground">
                                                    Source
                                                </th>
                                                <th className="px-4 py-2 font-medium text-muted-foreground">
                                                    Listing
                                                </th>
                                                <th className="px-4 py-2 font-medium text-muted-foreground">
                                                    Field
                                                </th>
                                                <th className="px-4 py-2 font-medium text-muted-foreground">
                                                    Old → New
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {recent_corrections.map((c) => (
                                                <tr key={c.id} className="border-t">
                                                    <td className="whitespace-nowrap px-4 py-2 font-mono text-xs tabular-nums text-muted-foreground">
                                                        {c.created_at}
                                                    </td>
                                                    <td className="px-4 py-2">{c.source_type}</td>
                                                    <td className="px-4 py-2">
                                                        <a
                                                            href={discoverLinkFor(c.source_type)}
                                                            className="text-teal-700 hover:underline dark:text-teal-300"
                                                        >
                                                            {c.listing_role_title ?? (
                                                                <span className="font-mono tabular-nums">
                                                                    {`#${c.listing_id}`}
                                                                </span>
                                                            )}
                                                        </a>
                                                    </td>
                                                    <td className="px-4 py-2">{c.field}</td>
                                                    <td className="px-4 py-2 text-xs">
                                                        <span className="font-mono text-muted-foreground">
                                                            {summarizeValue(c.old_value)}
                                                        </span>
                                                        <span className="px-1 text-muted-foreground">
                                                            →
                                                        </span>
                                                        <span className="font-mono">
                                                            {summarizeValue(c.new_value)}
                                                        </span>
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                )}
                            </CardContent>
                        </Card>
                    </>
                )}
            </div>

            <Dialog open={showConfirm} onOpenChange={setShowConfirm}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Re-parse every listing?</DialogTitle>
                        <DialogDescription>
                            This re-runs the parser against every aggregator listing for this tenant.
                            Type <code className="rounded bg-muted px-1 font-mono">YES</code> below to confirm.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-2">
                        <Label htmlFor="reparse-confirm">Confirmation</Label>
                        <Input
                            id="reparse-confirm"
                            value={confirmText}
                            onChange={(e) => setConfirmText(e.target.value)}
                            placeholder="Type YES to confirm"
                            autoFocus
                        />
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setShowConfirm(false)}>
                            Cancel
                        </Button>
                        <Button
                            variant="destructive"
                            disabled={confirmText !== 'YES'}
                            onClick={submitReparseAll}
                        >
                            Re-parse all
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AuthenticatedLayout>
    );
}
