import { Head, Link, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';

interface RunRow {
    id: number;
    started_at: string | null;
    finished_at: string | null;
    duration_seconds: number | null;
    source_kind: string;
    source: string;
    source_label: string;
    company_id: number | null;
    status: string;
    fetched: number;
    new_count: number;
    error_count: number;
}

interface SourceOption {
    value: string;
    label: string;
}

interface HealthEntry {
    kind: string;
    value: string;
    label: string;
    reason: string;
    last_run_at: string | null;
    consecutive_failures: number;
}

interface FilterState {
    date_from: string | null;
    date_to: string | null;
    source_kind: string | null;
    source_id: string[];
    status: string | null;
    failed_24h: boolean;
}

interface Props {
    runs: {
        data: RunRow[];
        meta: { current_page: number; last_page: number; per_page: number; total: number };
        links: { prev: string | null; next: string | null };
    };
    filters: FilterState;
    options: {
        source_kinds: string[];
        statuses: string[];
        sources: SourceOption[];
    };
    health: HealthEntry[];
}

function statusBadge(status: string): string {
    switch (status) {
        case 'completed':
            return 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300';
        case 'partial':
            return 'bg-amber-500/15 text-amber-700 dark:text-amber-300';
        case 'error':
            return 'bg-red-500/15 text-red-700 dark:text-red-300';
        case 'running':
            return 'bg-sky-500/15 text-sky-700 dark:text-sky-300';
        default:
            return 'bg-muted text-muted-foreground';
    }
}

function formatDuration(seconds: number | null): string {
    if (seconds === null) {
        return '—';
    }
    if (seconds < 60) {
        return `${seconds}s`;
    }
    const m = Math.floor(seconds / 60);
    const s = seconds % 60;
    return s === 0 ? `${m}m` : `${m}m ${s}s`;
}

function buildQuery(filters: Partial<FilterState>): Record<string, string | string[]> {
    const out: Record<string, string | string[]> = {};
    if (filters.date_from) out.date_from = filters.date_from;
    if (filters.date_to) out.date_to = filters.date_to;
    if (filters.source_kind) out.source_kind = filters.source_kind;
    if (filters.source_id && filters.source_id.length > 0) out.source_id = filters.source_id;
    if (filters.status) out.status = filters.status;
    if (filters.failed_24h) out.failed_24h = '1';
    return out;
}

export default function RunsIndex({ runs, filters, options, health }: Props) {
    const [draft, setDraft] = useState<FilterState>(filters);

    const sourceKindOptions = useMemo(
        () => ['all', ...options.source_kinds],
        [options.source_kinds],
    );
    const statusOptions = useMemo(() => ['all', ...options.statuses], [options.statuses]);

    function apply(next: Partial<FilterState>) {
        const merged: FilterState = { ...draft, ...next };
        setDraft(merged);
        router.get('/admin/runs', buildQuery(merged), {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        });
    }

    function presetRange(days: number) {
        const today = new Date();
        const start = new Date(today);
        start.setDate(start.getDate() - days);
        const fmt = (d: Date) => d.toISOString().slice(0, 10);
        apply({ date_from: fmt(start), date_to: fmt(today) });
    }

    function clearFilters() {
        setDraft({
            date_from: null,
            date_to: null,
            source_kind: null,
            source_id: [],
            status: null,
            failed_24h: false,
        });
        router.get('/admin/runs', {}, { preserveScroll: true, replace: true });
    }

    function toggleSource(value: string) {
        const next = draft.source_id.includes(value)
            ? draft.source_id.filter((v) => v !== value)
            : [...draft.source_id, value];
        apply({ source_id: next });
    }

    function filterToSource(value: string) {
        apply({ source_id: [value] });
    }

    return (
        <AuthenticatedLayout>
            <Head title="Scrape runs" />
            <div className="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
                <header className="flex flex-col gap-1">
                    <h1 className="text-2xl font-semibold tracking-tight">Scrape runs</h1>
                    <p className="text-muted-foreground text-sm">
                        Every ATS and aggregator scrape run, newest first. Click a row to drill into errors and captured artifacts.
                    </p>
                </header>

                {health.length > 0 && (
                    <Card className="border-amber-500/50">
                        <CardHeader>
                            <CardTitle className="text-sm">Source health — {health.length} {health.length === 1 ? 'source needs attention' : 'sources need attention'}</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="flex flex-wrap gap-2">
                                {health.map((h) => (
                                    <button
                                        type="button"
                                        key={`${h.kind}-${h.value}`}
                                        onClick={() => filterToSource(h.value)}
                                        title={h.reason}
                                        className="rounded-md border border-amber-500/40 bg-amber-500/10 px-3 py-1.5 text-left text-xs font-medium text-amber-800 hover:bg-amber-500/20 dark:text-amber-200"
                                    >
                                        <span className="block font-semibold">{h.label}</span>
                                        <span className="text-amber-700/80 dark:text-amber-300/80">{h.reason}</span>
                                    </button>
                                ))}
                            </div>
                        </CardContent>
                    </Card>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle className="text-sm">Filters</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div className="flex flex-wrap gap-2">
                            <Button type="button" size="sm" variant="outline" onClick={() => presetRange(0)}>
                                Today
                            </Button>
                            <Button type="button" size="sm" variant="outline" onClick={() => presetRange(7)}>
                                7d
                            </Button>
                            <Button type="button" size="sm" variant="outline" onClick={() => presetRange(30)}>
                                30d
                            </Button>
                            <Button
                                type="button"
                                size="sm"
                                variant={draft.failed_24h ? 'default' : 'outline'}
                                onClick={() => apply({ failed_24h: ! draft.failed_24h })}
                            >
                                Failed in last 24h
                            </Button>
                            <Button type="button" size="sm" variant="ghost" onClick={clearFilters}>
                                All sources
                            </Button>
                        </div>

                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            <label className="flex flex-col gap-1 text-xs font-medium">
                                <span>Date from</span>
                                <Input
                                    type="date"
                                    value={draft.date_from ?? ''}
                                    onChange={(e) => apply({ date_from: e.target.value || null })}
                                />
                            </label>
                            <label className="flex flex-col gap-1 text-xs font-medium">
                                <span>Date to</span>
                                <Input
                                    type="date"
                                    value={draft.date_to ?? ''}
                                    onChange={(e) => apply({ date_to: e.target.value || null })}
                                />
                            </label>
                            <label className="flex flex-col gap-1 text-xs font-medium">
                                <span>Source kind</span>
                                <select
                                    className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                                    value={draft.source_kind ?? 'all'}
                                    onChange={(e) => apply({ source_kind: e.target.value === 'all' ? null : e.target.value })}
                                >
                                    {sourceKindOptions.map((k) => (
                                        <option key={k} value={k}>
                                            {k}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label className="flex flex-col gap-1 text-xs font-medium">
                                <span>Status</span>
                                <select
                                    className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                                    value={draft.status ?? 'all'}
                                    onChange={(e) => apply({ status: e.target.value === 'all' ? null : e.target.value })}
                                >
                                    {statusOptions.map((s) => (
                                        <option key={s} value={s}>
                                            {s}
                                        </option>
                                    ))}
                                </select>
                            </label>
                        </div>

                        {options.sources.length > 0 && (
                            <div className="flex flex-col gap-2">
                                <span className="text-xs font-medium">Source</span>
                                <div className="flex flex-wrap gap-1.5">
                                    {options.sources.map((opt) => {
                                        const active = draft.source_id.includes(opt.value);
                                        return (
                                            <button
                                                key={opt.value}
                                                type="button"
                                                onClick={() => toggleSource(opt.value)}
                                                className={
                                                    'rounded-md border px-2.5 py-1 text-xs ' +
                                                    (active
                                                        ? 'border-primary bg-primary text-primary-foreground'
                                                        : 'border-input bg-background hover:bg-accent')
                                                }
                                            >
                                                {opt.label}
                                            </button>
                                        );
                                    })}
                                </div>
                            </div>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-sm">
                            {runs.meta.total} {runs.meta.total === 1 ? 'run' : 'runs'}
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        {runs.data.length === 0 ? (
                            <p className="px-6 py-8 text-center text-sm text-muted-foreground">No runs match these filters.</p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead className="bg-muted/50 text-xs uppercase tracking-wide">
                                        <tr>
                                            <th className="px-4 py-2 text-left">Started</th>
                                            <th className="px-4 py-2 text-left">Duration</th>
                                            <th className="px-4 py-2 text-left">Kind</th>
                                            <th className="px-4 py-2 text-left">Source</th>
                                            <th className="px-4 py-2 text-left">Status</th>
                                            <th className="px-4 py-2 text-right">Fetched</th>
                                            <th className="px-4 py-2 text-right">New</th>
                                            <th className="px-4 py-2 text-right">Errors</th>
                                            <th className="px-4 py-2"></th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y">
                                        {runs.data.map((run) => (
                                            <tr key={run.id} className="hover:bg-accent/40">
                                                <td className="px-4 py-2 whitespace-nowrap">
                                                    {run.started_at ? new Date(run.started_at).toLocaleString() : '—'}
                                                </td>
                                                <td className="px-4 py-2">{formatDuration(run.duration_seconds)}</td>
                                                <td className="px-4 py-2">
                                                    <Badge variant="outline" className="text-xs uppercase">
                                                        {run.source_kind}
                                                    </Badge>
                                                </td>
                                                <td className="px-4 py-2">{run.source_label}</td>
                                                <td className="px-4 py-2">
                                                    <span className={'rounded px-2 py-0.5 text-xs uppercase ' + statusBadge(run.status)}>
                                                        {run.status}
                                                    </span>
                                                </td>
                                                <td className="px-4 py-2 text-right tabular-nums">{run.fetched}</td>
                                                <td className="px-4 py-2 text-right tabular-nums">{run.new_count}</td>
                                                <td className={'px-4 py-2 text-right tabular-nums ' + (run.error_count > 0 ? 'text-red-600 dark:text-red-400' : '')}>
                                                    {run.error_count}
                                                </td>
                                                <td className="px-4 py-2 text-right">
                                                    <Link href={`/admin/runs/${run.id}`} className="text-primary text-xs hover:underline">
                                                        View
                                                    </Link>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}

                        {runs.meta.last_page > 1 && (
                            <div className="flex items-center justify-between border-t px-6 py-3 text-xs">
                                <span className="text-muted-foreground">
                                    Page {runs.meta.current_page} of {runs.meta.last_page}
                                </span>
                                <div className="flex gap-2">
                                    {runs.links.prev && (
                                        <Link
                                            href={runs.links.prev}
                                            className="border-input hover:bg-accent rounded-md border px-3 py-1"
                                            preserveScroll
                                        >
                                            Previous
                                        </Link>
                                    )}
                                    {runs.links.next && (
                                        <Link
                                            href={runs.links.next}
                                            className="border-input hover:bg-accent rounded-md border px-3 py-1"
                                            preserveScroll
                                        >
                                            Next
                                        </Link>
                                    )}
                                </div>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
