import { Head, Link, router } from '@inertiajs/react';
import { Fragment, useState } from 'react';

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';

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

function outcomeBadge(outcome: string): string {
    switch (outcome) {
        case 'success':
            return 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300';
        case 'failure':
            return 'bg-red-500/15 text-red-700 dark:text-red-300';
        default:
            return 'bg-muted text-muted-foreground';
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
            <div className="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
                <header className="flex flex-col gap-1">
                    <h1 className="text-2xl font-semibold tracking-tight">Audit log</h1>
                    <p className="text-muted-foreground text-sm">
                        Append-only record of security and operational events — logins, password changes, two-factor changes,
                        and bulk operations. Newest first.
                    </p>
                </header>

                <Card className="rounded-2xl">
                    <CardHeader>
                        <CardTitle className="text-sm">Filters</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            <label className="flex flex-col gap-1 text-xs font-medium">
                                <span>Action</span>
                                <select
                                    className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                                    value={draft.action ?? 'all'}
                                    onChange={(e) => apply({ action: e.target.value === 'all' ? null : e.target.value })}
                                >
                                    {actionOptions.map((a) => (
                                        <option key={a} value={a}>
                                            {a}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label className="flex flex-col gap-1 text-xs font-medium">
                                <span>Outcome</span>
                                <select
                                    className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                                    value={draft.outcome ?? 'all'}
                                    onChange={(e) => apply({ outcome: e.target.value === 'all' ? null : e.target.value })}
                                >
                                    {outcomeOptions.map((o) => (
                                        <option key={o} value={o}>
                                            {o}
                                        </option>
                                    ))}
                                </select>
                            </label>
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
                        </div>
                        <div>
                            <Button type="button" size="sm" variant="ghost" onClick={clearFilters}>
                                Clear filters
                            </Button>
                        </div>
                    </CardContent>
                </Card>

                <Card className="rounded-2xl">
                    <CardHeader>
                        <CardTitle className="text-sm">
                            <span className="font-mono tabular-nums">{logs.meta.total}</span>{' '}
                            {logs.meta.total === 1 ? 'entry' : 'entries'}
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        {logs.data.length === 0 ? (
                            <p className="text-muted-foreground px-6 py-8 text-center text-sm">No audit entries match these filters.</p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead className="bg-muted/50 font-mono text-xs uppercase tracking-wide">
                                        <tr>
                                            <th scope="col" className="px-4 py-2 text-left">When</th>
                                            <th scope="col" className="px-4 py-2 text-left">Action</th>
                                            <th scope="col" className="px-4 py-2 text-left">Outcome</th>
                                            <th scope="col" className="px-4 py-2 text-left">Actor</th>
                                            <th scope="col" className="px-4 py-2 text-left">IP</th>
                                            <th scope="col" className="px-4 py-2"></th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y">
                                        {logs.data.map((log) => (
                                            <Fragment key={log.id}>
                                                <tr className="hover:bg-accent/40">
                                                    <td className="px-4 py-2 font-mono tabular-nums whitespace-nowrap">
                                                        {log.created_at ? new Date(log.created_at).toLocaleString() : '—'}
                                                    </td>
                                                    <td className="px-4 py-2 font-mono text-xs">{log.action}</td>
                                                    <td className="px-4 py-2">
                                                        <span className={'rounded-full px-2 py-0.5 font-mono text-xs uppercase ' + outcomeBadge(log.outcome)}>
                                                            {log.outcome}
                                                        </span>
                                                    </td>
                                                    <td className="px-4 py-2">
                                                        {log.actor ? (
                                                            <span title={log.actor.email}>{log.actor.name}</span>
                                                        ) : (
                                                            <Badge variant="outline" className="text-xs">anonymous</Badge>
                                                        )}
                                                    </td>
                                                    <td className="text-muted-foreground px-4 py-2 font-mono text-xs">{log.ip ?? '—'}</td>
                                                    <td className="px-4 py-2 text-right">
                                                        <button
                                                            type="button"
                                                            onClick={() => setExpanded(expanded === log.id ? null : log.id)}
                                                            aria-expanded={expanded === log.id}
                                                            className="text-primary text-xs hover:underline"
                                                        >
                                                            {expanded === log.id ? 'Hide' : 'Details'}
                                                        </button>
                                                    </td>
                                                </tr>
                                                {expanded === log.id && (
                                                    <tr className="bg-muted/30">
                                                        <td colSpan={6} className="px-4 py-3">
                                                            <dl className="grid grid-cols-1 gap-2 text-xs sm:grid-cols-2">
                                                                <div>
                                                                    <dt className="text-muted-foreground font-medium">User agent</dt>
                                                                    <dd className="font-mono break-all">{log.user_agent ?? '—'}</dd>
                                                                </div>
                                                                <div>
                                                                    <dt className="text-muted-foreground font-medium">Context</dt>
                                                                    <dd>
                                                                        <pre className="bg-background overflow-x-auto rounded-md border p-2 font-mono text-xs">
                                                                            {JSON.stringify(log.context, null, 2)}
                                                                        </pre>
                                                                    </dd>
                                                                </div>
                                                            </dl>
                                                        </td>
                                                    </tr>
                                                )}
                                            </Fragment>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}

                        {logs.meta.last_page > 1 && (
                            <div className="flex items-center justify-between border-t px-6 py-3 text-xs">
                                <span className="text-muted-foreground">
                                    Page <span className="font-mono tabular-nums">{logs.meta.current_page}</span> of{' '}
                                    <span className="font-mono tabular-nums">{logs.meta.last_page}</span>
                                </span>
                                <div className="flex gap-2">
                                    {logs.links.prev && (
                                        <Link
                                            href={logs.links.prev}
                                            className="border-input hover:bg-accent rounded-md border px-3 py-1"
                                            preserveScroll
                                        >
                                            Previous
                                        </Link>
                                    )}
                                    {logs.links.next && (
                                        <Link
                                            href={logs.links.next}
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
