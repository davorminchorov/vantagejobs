import { Head, Link, router, usePage, usePoll } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { SectionLabel } from '@/components/ui/section-label';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { NativeSelect } from '@/components/ui/native-select';
import PageHeader from '@/components/PageHeader';
import { RelativeDate } from '@/lib/dates';

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

interface AtsTypeOption {
    value: string;
    label: string;
    count: number;
}

interface AggregatorOption {
    value: string;
    label: string;
}

interface TriggerOptions {
    ats_types: AtsTypeOption[];
    aggregators: AggregatorOption[];
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
    triggerOptions: TriggerOptions;
    activeRunCount: number;
}

function statusVariant(status: string): 'success' | 'warning' | 'destructive' | 'secondary' | 'outline' {
    switch (status) {
        case 'completed':
            return 'success';
        case 'partial':
            return 'warning';
        case 'error':
            return 'destructive';
        case 'running':
            return 'outline';
        default:
            return 'secondary';
    }
}

const RUNNING_CLASS = 'bg-sky-50 text-sky-700 inset-ring-sky-600/20 dark:bg-sky-400/10 dark:text-sky-300 dark:inset-ring-sky-400/20';

function StatusBadge({ status }: { status: string }) {
    return (
        <Badge variant={statusVariant(status)} className={status === 'running' ? RUNNING_CLASS : undefined}>
            {status}
        </Badge>
    );
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

export default function RunsIndex({ runs, filters, options, health, triggerOptions, activeRunCount }: Props) {
    const [draft, setDraft] = useState<FilterState>(filters);

    const [selectedAggregator, setSelectedAggregator] = useState<string>(triggerOptions.aggregators[0]?.value ?? '');

    const flash = (usePage().props.flash ?? null) as { success?: string | null; error?: string | null } | null;

    const { start, stop } = usePoll(2500, { only: ['runs', 'health', 'activeRunCount'] }, { autoStart: false });

    // Poll while any scrape is in flight; stop as soon as the count hits zero.
    useEffect(() => {
        if (activeRunCount > 0) {
            start();
        } else {
            stop();
        }
    }, [activeRunCount, start, stop]);

    function trigger(scope: string) {
        router.post(
            '/admin/runs/trigger',
            { scope },
            {
                preserveScroll: true,
                preserveState: true,
                only: ['runs', 'health', 'activeRunCount', 'flash'],
                onSuccess: () => start(),
            },
        );
    }

    const sourceKindOptions = useMemo(() => ['all', ...options.source_kinds], [options.source_kinds]);
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
        const next = draft.source_id.includes(value) ? draft.source_id.filter((v) => v !== value) : [...draft.source_id, value];
        apply({ source_id: next });
    }

    function filterToSource(value: string) {
        apply({ source_id: [value] });
    }

    return (
        <AuthenticatedLayout>
            <Head title="Scrape runs" />
            <div className="flex flex-col gap-8">
                <PageHeader
                    title="Scrape runs"
                    subtitle="Every ATS and aggregator scrape run, newest first. Click a row to drill into errors and captured artifacts."
                />

                {flash?.success && (
                    <div className="rounded-lg border border-emerald-600/20 bg-emerald-50 px-4 py-2 text-base text-emerald-700 sm:text-sm dark:border-emerald-400/20 dark:bg-emerald-400/10 dark:text-emerald-300">
                        {flash.success}
                    </div>
                )}

                <section className="flex flex-col gap-4">
                    <div className="flex items-center justify-between gap-3">
                        <SectionLabel>Run scrapers</SectionLabel>
                        {activeRunCount > 0 && (
                            <span className="flex items-center gap-1.5 text-sm font-medium text-sky-700 sm:text-xs dark:text-sky-300">
                                <span className="size-2 shrink-0 animate-pulse rounded-full bg-sky-500" />
                                {activeRunCount} running
                            </span>
                        )}
                    </div>

                    <div>
                        <Button type="button" onClick={() => trigger('all')}>
                            Run everything
                        </Button>
                    </div>

                    <div className="flex flex-col gap-2">
                        <span className="text-sm font-medium">ATS scrapers</span>
                        <div className="flex flex-wrap gap-2">
                            <Button type="button" size="sm" variant="outline" onClick={() => trigger('ats')}>
                                Run all ATS
                            </Button>
                            {triggerOptions.ats_types.map((t) => (
                                <Button key={t.value} type="button" size="sm" variant="outline" onClick={() => trigger(`ats:${t.value}`)}>
                                    {t.label} ({t.count})
                                </Button>
                            ))}
                        </div>
                    </div>

                    <div className="flex flex-col gap-2">
                        <span className="text-sm font-medium">Aggregators</span>
                        <div className="flex flex-wrap items-center gap-2">
                            <Button type="button" size="sm" variant="outline" onClick={() => trigger('aggregators')}>
                                Run all aggregators
                            </Button>
                            {triggerOptions.aggregators.length > 0 && (
                                <>
                                    <NativeSelect
                                        name="aggregator"
                                        aria-label="Aggregator"
                                        value={selectedAggregator}
                                        onChange={(e) => setSelectedAggregator(e.target.value)}
                                    >
                                        {triggerOptions.aggregators.map((a) => (
                                            <option key={a.value} value={a.value}>
                                                {a.label}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        disabled={!selectedAggregator}
                                        onClick={() => trigger(`aggregator:${selectedAggregator}`)}
                                    >
                                        Run
                                    </Button>
                                </>
                            )}
                        </div>
                    </div>
                </section>

                {health.length > 0 && (
                    <section className="flex flex-col gap-3 border-t border-border pt-6">
                        <SectionLabel>
                            Source health: {health.length} {health.length === 1 ? 'source needs attention' : 'sources need attention'}
                        </SectionLabel>
                        <ul role="list" className="flex flex-wrap gap-2">
                            {health.map((h) => (
                                <li key={`${h.kind}-${h.value}`} className="min-w-0">
                                    <button
                                        type="button"
                                        onClick={() => filterToSource(h.value)}
                                        title={h.reason}
                                        className="flex max-w-full flex-col rounded-lg bg-amber-50 px-3 py-2 text-left text-base text-amber-800 inset-ring inset-ring-amber-600/20 hover:bg-amber-100 sm:text-sm dark:bg-amber-400/10 dark:text-amber-300 dark:inset-ring-amber-400/20 dark:hover:bg-amber-400/20"
                                    >
                                        <span className="truncate font-medium">{h.label}</span>
                                        <span className="truncate opacity-80">{h.reason}</span>
                                    </button>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                <section className="flex flex-col gap-4 border-t border-border pt-6">
                    <SectionLabel>Filters</SectionLabel>
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
                            variant="outline"
                            aria-pressed={draft.failed_24h}
                            className={draft.failed_24h ? 'bg-muted font-semibold' : undefined}
                            onClick={() => apply({ failed_24h: !draft.failed_24h })}
                        >
                            Failed in last 24h
                        </Button>
                        <Button type="button" size="sm" variant="ghost" onClick={clearFilters}>
                            All sources
                        </Button>
                    </div>

                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="runs-date-from">Date from</Label>
                            <Input
                                id="runs-date-from"
                                name="date_from"
                                type="date"
                                value={draft.date_from ?? ''}
                                onChange={(e) => apply({ date_from: e.target.value || null })}
                            />
                        </div>
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="runs-date-to">Date to</Label>
                            <Input
                                id="runs-date-to"
                                name="date_to"
                                type="date"
                                value={draft.date_to ?? ''}
                                onChange={(e) => apply({ date_to: e.target.value || null })}
                            />
                        </div>
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="runs-source-kind">Source kind</Label>
                            <NativeSelect
                                id="runs-source-kind"
                                name="source_kind"
                                value={draft.source_kind ?? 'all'}
                                onChange={(e) => apply({ source_kind: e.target.value === 'all' ? null : e.target.value })}
                            >
                                {sourceKindOptions.map((k) => (
                                    <option key={k} value={k}>
                                        {k}
                                    </option>
                                ))}
                            </NativeSelect>
                        </div>
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="runs-status">Status</Label>
                            <NativeSelect
                                id="runs-status"
                                name="status"
                                value={draft.status ?? 'all'}
                                onChange={(e) => apply({ status: e.target.value === 'all' ? null : e.target.value })}
                            >
                                {statusOptions.map((s) => (
                                    <option key={s} value={s}>
                                        {s}
                                    </option>
                                ))}
                            </NativeSelect>
                        </div>
                    </div>

                    {options.sources.length > 0 && (
                        <div className="flex flex-col gap-2">
                            <span className="text-sm font-medium">Source</span>
                            <div className="flex flex-wrap gap-2">
                                {options.sources.map((opt) => {
                                    const active = draft.source_id.includes(opt.value);
                                    return (
                                        <button
                                            key={opt.value}
                                            type="button"
                                            aria-pressed={active}
                                            onClick={() => toggleSource(opt.value)}
                                            className={
                                                'rounded-md border px-2.5 py-1 text-base sm:text-sm ' +
                                                (active
                                                    ? 'border-border bg-muted font-semibold text-foreground'
                                                    : 'border-input bg-background text-muted-foreground hover:bg-muted')
                                            }
                                        >
                                            {opt.label}
                                        </button>
                                    );
                                })}
                            </div>
                        </div>
                    )}
                </section>

                <section className="flex flex-col gap-3 border-t border-border pt-6">
                    <SectionLabel>
                        <span className="tabular-nums">{runs.meta.total}</span> {runs.meta.total === 1 ? 'run' : 'runs'}
                    </SectionLabel>

                    {runs.data.length === 0 ? (
                        <EmptyState>No runs match these filters.</EmptyState>
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Started</TableHead>
                                    <TableHead>Duration</TableHead>
                                    <TableHead>Kind</TableHead>
                                    <TableHead>Source</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead className="text-right">Fetched</TableHead>
                                    <TableHead className="text-right">New</TableHead>
                                    <TableHead className="text-right">Errors</TableHead>
                                    <TableHead>
                                        <span className="sr-only">Actions</span>
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {runs.data.map((run) => (
                                    <TableRow key={run.id} className="hover:bg-muted/50">
                                        <TableCell className="whitespace-nowrap tabular-nums">
                                            {run.started_at ? <RelativeDate iso={run.started_at} /> : '—'}
                                        </TableCell>
                                        <TableCell className="whitespace-nowrap tabular-nums">
                                            {formatDuration(run.duration_seconds)}
                                        </TableCell>
                                        <TableCell>
                                            <Badge variant="outline" className="font-mono">
                                                {run.source_kind}
                                            </Badge>
                                        </TableCell>
                                        <TableCell>{run.source_label}</TableCell>
                                        <TableCell>
                                            <StatusBadge status={run.status} />
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">{run.fetched}</TableCell>
                                        <TableCell className="text-right tabular-nums">{run.new_count}</TableCell>
                                        <TableCell
                                            className={
                                                'text-right tabular-nums ' + (run.error_count > 0 ? 'text-rose-700 dark:text-rose-300' : '')
                                            }
                                        >
                                            {run.error_count}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <Button asChild variant="ghost" size="sm">
                                                <Link href={`/admin/runs/${run.id}`}>View</Link>
                                            </Button>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}

                    {runs.meta.last_page > 1 && (
                        <div className="flex items-center justify-between gap-3 border-t border-border pt-4 text-base sm:text-sm">
                            <span className="text-muted-foreground">
                                Page <span className="tabular-nums">{runs.meta.current_page}</span> of{' '}
                                <span className="tabular-nums">{runs.meta.last_page}</span>
                            </span>
                            <div className="flex gap-2">
                                {runs.links.prev && (
                                    <Button asChild variant="outline" size="sm">
                                        <Link href={runs.links.prev} preserveScroll>
                                            Previous
                                        </Link>
                                    </Button>
                                )}
                                {runs.links.next && (
                                    <Button asChild variant="outline" size="sm">
                                        <Link href={runs.links.next} preserveScroll>
                                            Next
                                        </Link>
                                    </Button>
                                )}
                            </div>
                        </div>
                    )}
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
