import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

interface RunSummary {
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
    closed_count: number;
    error_count: number;
}

interface ErrorEntry {
    message: string;
    context: Record<string, unknown> | null;
}

interface ArtifactRow {
    id: number;
    connector: string;
    request_endpoint: string;
    http_status: number | null;
    size_bytes: number;
    sha256: string;
    fetched_at: string | null;
    retained_until: string | null;
    expired: boolean;
    download_url: string | null;
}

interface Props {
    run: RunSummary;
    errors: ErrorEntry[];
    artifacts: ArtifactRow[];
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

function formatBytes(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / 1024 / 1024).toFixed(2)} MB`;
}

function truncate(value: string, max: number): string {
    if (value.length <= max) return value;
    return value.slice(0, max - 1) + '…';
}

function ErrorRow({ entry, index }: { entry: ErrorEntry; index: number }) {
    const [open, setOpen] = useState(false);
    const ctx = entry.context !== null ? JSON.stringify(entry.context, null, 2) : null;
    const ctxTruncated = ctx !== null && ctx.length > 1024;
    const ctxShown = ctxTruncated && ! open ? ctx.slice(0, 1024) + '\n…' : ctx;

    return (
        <li className="border-t px-6 py-3">
            <div className="flex items-start gap-3">
                <span className="text-muted-foreground text-xs font-mono">#{index + 1}</span>
                <div className="flex-1 space-y-2">
                    <p className="text-sm">{entry.message}</p>
                    {ctx !== null && (
                        <pre className="bg-muted/50 overflow-x-auto rounded p-2 font-mono text-xs">{ctxShown}</pre>
                    )}
                    {ctxTruncated && (
                        <button
                            type="button"
                            onClick={() => setOpen(! open)}
                            className="text-primary text-xs hover:underline"
                        >
                            {open ? 'Collapse' : 'Show full context'}
                        </button>
                    )}
                </div>
            </div>
        </li>
    );
}

export default function RunsShow({ run, errors, artifacts }: Props) {
    return (
        <AuthenticatedLayout>
            <Head title={`Run #${run.id}`} />
            <div className="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
                <header className="flex flex-col gap-2">
                    <Link href="/admin/runs" className="text-muted-foreground text-xs hover:underline">
                        ← All runs
                    </Link>
                    <div className="flex flex-wrap items-center gap-3">
                        <h1 className="text-2xl font-semibold tracking-tight">Run #{run.id}</h1>
                        <span className={'rounded px-2 py-0.5 text-xs uppercase ' + statusBadge(run.status)}>
                            {run.status}
                        </span>
                        <Badge variant="outline" className="text-xs uppercase">
                            {run.source_kind}
                        </Badge>
                        <span className="text-sm">{run.source_label}</span>
                    </div>
                </header>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-sm">Summary</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <dl className="grid grid-cols-2 gap-4 text-sm sm:grid-cols-4">
                            <div>
                                <dt className="text-muted-foreground text-xs">Started</dt>
                                <dd>{run.started_at ? new Date(run.started_at).toLocaleString() : '—'}</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground text-xs">Finished</dt>
                                <dd>{run.finished_at ? new Date(run.finished_at).toLocaleString() : '—'}</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground text-xs">Duration</dt>
                                <dd>{formatDuration(run.duration_seconds)}</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground text-xs">Source</dt>
                                <dd>{run.source}</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground text-xs">Fetched</dt>
                                <dd className="tabular-nums">{run.fetched}</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground text-xs">New</dt>
                                <dd className="tabular-nums">{run.new_count}</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground text-xs">Closed</dt>
                                <dd className="tabular-nums">{run.closed_count}</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground text-xs">Errors</dt>
                                <dd className={'tabular-nums ' + (run.error_count > 0 ? 'text-red-600 dark:text-red-400' : '')}>
                                    {run.error_count}
                                </dd>
                            </div>
                        </dl>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-sm">Errors ({errors.length})</CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        {errors.length === 0 ? (
                            <p className="px-6 py-4 text-sm text-muted-foreground">No errors recorded for this run.</p>
                        ) : (
                            <ul className="divide-y">
                                {errors.map((entry, idx) => (
                                    <ErrorRow key={idx} entry={entry} index={idx} />
                                ))}
                            </ul>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-sm">Artifacts ({artifacts.length})</CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        {artifacts.length === 0 ? (
                            <p className="px-6 py-4 text-sm text-muted-foreground">
                                No artifacts captured for this run. (Phase 1 ATS scrapes don't write artifacts, and aggregator runs that finished before step 7 won't have any.)
                            </p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead className="bg-muted/50 text-xs uppercase tracking-wide">
                                        <tr>
                                            <th className="px-4 py-2 text-left">Fetched</th>
                                            <th className="px-4 py-2 text-left">Connector</th>
                                            <th className="px-4 py-2 text-left">Endpoint</th>
                                            <th className="px-4 py-2 text-right">HTTP</th>
                                            <th className="px-4 py-2 text-right">Size</th>
                                            <th className="px-4 py-2 text-left">Retained until</th>
                                            <th className="px-4 py-2 text-right"></th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y">
                                        {artifacts.map((artifact) => (
                                            <tr key={artifact.id} className={artifact.expired ? 'opacity-50' : ''}>
                                                <td className="px-4 py-2 whitespace-nowrap">
                                                    {artifact.fetched_at ? new Date(artifact.fetched_at).toLocaleString() : '—'}
                                                </td>
                                                <td className="px-4 py-2 font-mono text-xs">{artifact.connector}</td>
                                                <td className="px-4 py-2 font-mono text-xs" title={artifact.request_endpoint}>
                                                    {truncate(artifact.request_endpoint, 80)}
                                                </td>
                                                <td className="px-4 py-2 text-right tabular-nums">{artifact.http_status ?? '—'}</td>
                                                <td className="px-4 py-2 text-right tabular-nums">{formatBytes(artifact.size_bytes)}</td>
                                                <td className="px-4 py-2 whitespace-nowrap text-xs">
                                                    {artifact.retained_until ? new Date(artifact.retained_until).toLocaleString() : '—'}
                                                </td>
                                                <td className="px-4 py-2 text-right">
                                                    {artifact.expired ? (
                                                        <Badge variant="outline" className="text-xs">
                                                            expired
                                                        </Badge>
                                                    ) : artifact.download_url ? (
                                                        <a
                                                            href={artifact.download_url}
                                                            className="text-primary text-xs hover:underline"
                                                        >
                                                            Download
                                                        </a>
                                                    ) : (
                                                        '—'
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
