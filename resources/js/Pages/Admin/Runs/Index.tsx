import { Head, Link, router, usePage, usePoll } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/components/PageHeader';
import { kindLabel, RunStatusBadge, formatDuration, statusLabel } from '@/components/runs/run-status';
import { SourceHealth, type HealthEntry } from '@/components/runs/source-health';
import { TriggerMenu, type TriggerOptions } from '@/components/runs/trigger-menu';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
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

type RangeKey = 'today' | '7d' | '30d' | 'all';

const RANGE_DAYS: Record<Exclude<RangeKey, 'all'>, number> = { today: 0, '7d': 7, '30d': 30 };

const ATS_PREFIX = '[ats] ';
const AGGREGATOR_PREFIX = '[aggregator] ';
const MULTIPLE_SOURCES = '__multiple';

function isoDay(date: Date): string {
    return date.toISOString().slice(0, 10);
}

function rangeDates(days: number): { date_from: string; date_to: string } {
    const today = new Date();
    const from = new Date(today);
    from.setDate(from.getDate() - days);
    return { date_from: isoDay(from), date_to: isoDay(today) };
}

function activeRange(filters: FilterState): RangeKey | '' {
    if (!filters.date_from && !filters.date_to) {
        return 'all';
    }
    for (const key of Object.keys(RANGE_DAYS) as Exclude<RangeKey, 'all'>[]) {
        const { date_from, date_to } = rangeDates(RANGE_DAYS[key]);
        if (filters.date_from === date_from && filters.date_to === date_to) {
            return key;
        }
    }
    return '';
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

function hasActiveFilters(filters: FilterState): boolean {
    return Object.keys(buildQuery(filters)).length > 0;
}

export default function RunsIndex({ runs, filters, options, health, triggerOptions, activeRunCount }: Props) {
    const [draft, setDraft] = useState<FilterState>(filters);

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

    const sourceGroups = useMemo(() => {
        const strip = (label: string, prefix: string) => (label.startsWith(prefix) ? label.slice(prefix.length) : label);
        return {
            ats: options.sources
                .filter((s) => s.label.startsWith(ATS_PREFIX))
                .map((s) => ({ value: s.value, label: strip(s.label, ATS_PREFIX) })),
            aggregators: options.sources
                .filter((s) => s.label.startsWith(AGGREGATOR_PREFIX))
                .map((s) => ({ value: s.value, label: strip(s.label, AGGREGATOR_PREFIX) })),
            other: options.sources.filter((s) => !s.label.startsWith(ATS_PREFIX) && !s.label.startsWith(AGGREGATOR_PREFIX)),
        };
    }, [options.sources]);

    function apply(next: Partial<FilterState>) {
        const merged: FilterState = { ...draft, ...next };
        setDraft(merged);
        router.get('/admin/runs', buildQuery(merged), {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        });
    }

    function changeRange(range: string) {
        if (range === 'all') {
            apply({ date_from: null, date_to: null });
            return;
        }
        apply(rangeDates(RANGE_DAYS[range as Exclude<RangeKey, 'all'>]));
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

    const sourceSelectValue = draft.source_id.length === 0 ? '' : draft.source_id.length === 1 ? draft.source_id[0] : MULTIPLE_SOURCES;

    return (
        <AuthenticatedLayout>
            <Head title="Scrape runs" />
            <div className="flex flex-col gap-6">
                <PageHeader
                    title="Scrape runs"
                    subtitle="Every ATS and aggregator scrape, newest first. Open a run to see its errors and captured artifacts."
                    actions={
                        <>
                            {activeRunCount > 0 && (
                                <span className="flex items-center gap-1.5 text-sm font-medium text-sky-700 dark:text-sky-300">
                                    <span className="size-2 shrink-0 animate-pulse rounded-full bg-sky-500" />
                                    <span className="tabular-nums">{activeRunCount}</span> running
                                </span>
                            )}
                            <TriggerMenu options={triggerOptions} onTrigger={trigger} />
                        </>
                    }
                />

                {flash?.success && (
                    <div className="rounded-lg border border-emerald-600/20 bg-emerald-50 px-4 py-2 text-base text-emerald-700 sm:text-sm dark:border-emerald-400/20 dark:bg-emerald-400/10 dark:text-emerald-300">
                        {flash.success}
                    </div>
                )}

                <div className="flex flex-col gap-3">
                    <Tabs value={activeRange(draft)} onValueChange={changeRange}>
                        <TabsList aria-label="Date range">
                            <TabsTrigger value="today">Today</TabsTrigger>
                            <TabsTrigger value="7d">7 days</TabsTrigger>
                            <TabsTrigger value="30d">30 days</TabsTrigger>
                            <TabsTrigger value="all">All time</TabsTrigger>
                        </TabsList>
                    </Tabs>

                    <div className="flex flex-wrap items-center gap-2">
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
                        <NativeSelect
                            name="status"
                            aria-label="Status"
                            value={draft.status ?? ''}
                            onChange={(e) => apply({ status: e.target.value || null })}
                        >
                            <option value="">All statuses</option>
                            {options.statuses.map((s) => (
                                <option key={s} value={s}>
                                    {statusLabel(s)}
                                </option>
                            ))}
                        </NativeSelect>
                        <NativeSelect
                            name="source_kind"
                            aria-label="Kind"
                            value={draft.source_kind ?? ''}
                            onChange={(e) => apply({ source_kind: e.target.value || null })}
                        >
                            <option value="">All kinds</option>
                            {options.source_kinds.map((k) => (
                                <option key={k} value={k}>
                                    {kindLabel(k)}
                                </option>
                            ))}
                        </NativeSelect>
                        <NativeSelect
                            name="source_id"
                            aria-label="Source"
                            wrapperClassName="max-w-full"
                            value={sourceSelectValue}
                            onChange={(e) => apply({ source_id: e.target.value ? [e.target.value] : [] })}
                        >
                            <option value="">All sources</option>
                            {draft.source_id.length > 1 && <option value={MULTIPLE_SOURCES}>{draft.source_id.length} sources</option>}
                            {sourceGroups.ats.length > 0 && (
                                <optgroup label="ATS">
                                    {sourceGroups.ats.map((s) => (
                                        <option key={s.value} value={s.value}>
                                            {s.label}
                                        </option>
                                    ))}
                                </optgroup>
                            )}
                            {sourceGroups.aggregators.length > 0 && (
                                <optgroup label="Aggregators">
                                    {sourceGroups.aggregators.map((s) => (
                                        <option key={s.value} value={s.value}>
                                            {s.label}
                                        </option>
                                    ))}
                                </optgroup>
                            )}
                            {sourceGroups.other.map((s) => (
                                <option key={s.value} value={s.value}>
                                    {s.label}
                                </option>
                            ))}
                        </NativeSelect>
                        <Input
                            name="date_from"
                            type="date"
                            aria-label="From date"
                            className="w-40"
                            value={draft.date_from ?? ''}
                            onChange={(e) => apply({ date_from: e.target.value || null })}
                        />
                        <Input
                            name="date_to"
                            type="date"
                            aria-label="To date"
                            className="w-40"
                            value={draft.date_to ?? ''}
                            onChange={(e) => apply({ date_to: e.target.value || null })}
                        />
                        {hasActiveFilters(draft) && (
                            <Button type="button" size="sm" variant="ghost" onClick={clearFilters}>
                                Clear filters
                            </Button>
                        )}
                    </div>
                </div>

                <section className="flex flex-col gap-3">
                    <p className="text-base text-muted-foreground sm:text-sm">
                        <span className="tabular-nums">{runs.meta.total}</span> {runs.meta.total === 1 ? 'run' : 'runs'}
                    </p>

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
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {runs.data.map((run) => (
                                    <TableRow
                                        key={run.id}
                                        className="cursor-pointer hover:bg-muted/50"
                                        onClick={() => router.visit(`/admin/runs/${run.id}`)}
                                    >
                                        <TableCell className="whitespace-nowrap tabular-nums">
                                            {run.started_at ? <RelativeDate iso={run.started_at} /> : '–'}
                                        </TableCell>
                                        <TableCell className="whitespace-nowrap tabular-nums">
                                            {formatDuration(run.duration_seconds)}
                                        </TableCell>
                                        <TableCell className="whitespace-nowrap text-muted-foreground">
                                            {kindLabel(run.source_kind)}
                                        </TableCell>
                                        <TableCell className="font-medium">
                                            <Link
                                                href={`/admin/runs/${run.id}`}
                                                onClick={(e) => e.stopPropagation()}
                                                className="hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                                            >
                                                {run.source_label}
                                            </Link>
                                        </TableCell>
                                        <TableCell>
                                            <RunStatusBadge status={run.status} />
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
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}

                    {runs.meta.last_page > 1 && (
                        <div className="flex items-center justify-between gap-3 pt-2 text-base sm:text-sm">
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

                <SourceHealth health={health} onSelectSource={(value) => apply({ source_id: [value] })} />
            </div>
        </AuthenticatedLayout>
    );
}
