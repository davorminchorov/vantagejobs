import { Head, Link, router } from '@inertiajs/react';
import { Fragment, useState } from 'react';

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

interface Actor {
    id: number;
    name: string;
    email: string;
}

interface LogRow {
    id: number;
    action: string;
    outcome: string;
    actor: Actor | null;
    ip: string | null;
    user_agent: string | null;
    context: Record<string, unknown>;
    created_at: string | null;
}

interface FilterState {
    action: string | null;
    outcome: string | null;
    date_from: string | null;
    date_to: string | null;
}

interface Props {
    logs: {
        data: LogRow[];
        meta: { current_page: number; last_page: number; per_page: number; total: number };
        links: { prev: string | null; next: string | null };
    };
    filters: FilterState;
    options: {
        actions: string[];
        outcomes: string[];
    };
}

function outcomeVariant(outcome: string): 'success' | 'destructive' | 'secondary' {
    switch (outcome) {
        case 'success':
            return 'success';
        case 'failure':
            return 'destructive';
        default:
            return 'secondary';
    }
}

function buildQuery(filters: Partial<FilterState>): Record<string, string> {
    const out: Record<string, string> = {};
    if (filters.action) out.action = filters.action;
    if (filters.outcome) out.outcome = filters.outcome;
    if (filters.date_from) out.date_from = filters.date_from;
    if (filters.date_to) out.date_to = filters.date_to;
    return out;
}

export default function AuditIndex({ logs, filters, options }: Props) {
    const [draft, setDraft] = useState<FilterState>(filters);
    const [expanded, setExpanded] = useState<number | null>(null);

    const actionOptions = ['all', ...options.actions];
    const outcomeOptions = ['all', ...options.outcomes];

    function apply(next: Partial<FilterState>) {
        const merged: FilterState = { ...draft, ...next };
        setDraft(merged);
        router.get('/admin/audit', buildQuery(merged), {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        });
    }

    function clearFilters() {
        const cleared: FilterState = {
            action: null,
            outcome: null,
            date_from: null,
            date_to: null,
        };
        setDraft(cleared);
        router.get('/admin/audit', {}, { preserveScroll: true, replace: true });
    }

    return (
        <AuthenticatedLayout>
            <Head title="Audit log" />
            <div className="flex flex-col gap-8">
                <PageHeader
                    title="Audit log"
                    subtitle="Append-only record of security and operational events: logins, password changes, two-factor changes, and bulk operations. Newest first."
                />

                <section className="flex flex-col gap-4">
                    <SectionLabel>Filters</SectionLabel>
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="audit-action">Action</Label>
                            <NativeSelect
                                id="audit-action"
                                name="action"
                                value={draft.action ?? 'all'}
                                onChange={(e) => apply({ action: e.target.value === 'all' ? null : e.target.value })}
                            >
                                {actionOptions.map((a) => (
                                    <option key={a} value={a}>
                                        {a}
                                    </option>
                                ))}
                            </NativeSelect>
                        </div>
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="audit-outcome">Outcome</Label>
                            <NativeSelect
                                id="audit-outcome"
                                name="outcome"
                                value={draft.outcome ?? 'all'}
                                onChange={(e) => apply({ outcome: e.target.value === 'all' ? null : e.target.value })}
                            >
                                {outcomeOptions.map((o) => (
                                    <option key={o} value={o}>
                                        {o}
                                    </option>
                                ))}
                            </NativeSelect>
                        </div>
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="audit-date-from">Date from</Label>
                            <Input
                                id="audit-date-from"
                                name="date_from"
                                type="date"
                                value={draft.date_from ?? ''}
                                onChange={(e) => apply({ date_from: e.target.value || null })}
                            />
                        </div>
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="audit-date-to">Date to</Label>
                            <Input
                                id="audit-date-to"
                                name="date_to"
                                type="date"
                                value={draft.date_to ?? ''}
                                onChange={(e) => apply({ date_to: e.target.value || null })}
                            />
                        </div>
                    </div>
                    <div>
                        <Button type="button" size="sm" variant="ghost" onClick={clearFilters}>
                            Clear filters
                        </Button>
                    </div>
                </section>

                <section className="border-border flex flex-col gap-3 border-t pt-6">
                    <SectionLabel>
                        <span className="tabular-nums">{logs.meta.total}</span> {logs.meta.total === 1 ? 'entry' : 'entries'}
                    </SectionLabel>

                    {logs.data.length === 0 ? (
                        <EmptyState>No audit entries match these filters.</EmptyState>
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead scope="col">When</TableHead>
                                    <TableHead scope="col">Action</TableHead>
                                    <TableHead scope="col">Outcome</TableHead>
                                    <TableHead scope="col">Actor</TableHead>
                                    <TableHead scope="col">IP</TableHead>
                                    <TableHead scope="col">
                                        <span className="sr-only">Actions</span>
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {logs.data.map((log) => (
                                    <Fragment key={log.id}>
                                        <TableRow className="hover:bg-muted/50">
                                            <TableCell className="whitespace-nowrap tabular-nums">
                                                {log.created_at ? <RelativeDate iso={log.created_at} /> : '—'}
                                            </TableCell>
                                            <TableCell className="font-mono">{log.action}</TableCell>
                                            <TableCell>
                                                <Badge variant={outcomeVariant(log.outcome)}>{log.outcome}</Badge>
                                            </TableCell>
                                            <TableCell>
                                                {log.actor ? (
                                                    <span title={log.actor.email}>{log.actor.name}</span>
                                                ) : (
                                                    <Badge variant="outline">anonymous</Badge>
                                                )}
                                            </TableCell>
                                            <TableCell className="text-muted-foreground font-mono tabular-nums">{log.ip ?? '—'}</TableCell>
                                            <TableCell className="text-right">
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() => setExpanded(expanded === log.id ? null : log.id)}
                                                    aria-expanded={expanded === log.id}
                                                >
                                                    {expanded === log.id ? 'Hide' : 'Details'}
                                                </Button>
                                            </TableCell>
                                        </TableRow>
                                        {expanded === log.id && (
                                            <TableRow>
                                                <TableCell colSpan={6} className="py-4">
                                                    <dl className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                                        <div className="flex min-w-0 flex-col gap-1">
                                                            <dt className="text-foreground text-sm font-medium">User agent</dt>
                                                            <dd className="text-muted-foreground font-mono text-sm break-all">{log.user_agent ?? '—'}</dd>
                                                        </div>
                                                        <div className="flex min-w-0 flex-col gap-1">
                                                            <dt className="text-foreground text-sm font-medium">Context</dt>
                                                            <dd>
                                                                <pre className="bg-muted/50 overflow-x-auto rounded-lg p-4 font-mono text-sm">
                                                                    {JSON.stringify(log.context, null, 2)}
                                                                </pre>
                                                            </dd>
                                                        </div>
                                                    </dl>
                                                </TableCell>
                                            </TableRow>
                                        )}
                                    </Fragment>
                                ))}
                            </TableBody>
                        </Table>
                    )}

                    {logs.meta.last_page > 1 && (
                        <div className="border-border flex items-center justify-between gap-3 border-t pt-4 text-base sm:text-sm">
                            <span className="text-muted-foreground">
                                Page <span className="tabular-nums">{logs.meta.current_page}</span> of{' '}
                                <span className="tabular-nums">{logs.meta.last_page}</span>
                            </span>
                            <div className="flex gap-2">
                                {logs.links.prev && (
                                    <Button asChild variant="outline" size="sm">
                                        <Link href={logs.links.prev} preserveScroll>
                                            Previous
                                        </Link>
                                    </Button>
                                )}
                                {logs.links.next && (
                                    <Button asChild variant="outline" size="sm">
                                        <Link href={logs.links.next} preserveScroll>
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
