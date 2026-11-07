import { Head, Link } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Badge } from '@/components/ui/badge';
import { formatDuration, kindLabel, RunStatusBadge } from '@/components/runs/run-status';
import { Button } from '@/components/ui/button';
import { SectionLabel } from '@/components/ui/section-label';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import PageHeader from '@/components/PageHeader';
import { formatDateTime, RelativeDate } from '@/lib/dates';

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
    const ctxShown = ctxTruncated && !open ? ctx.slice(0, 1024) + '\n…' : ctx;

    return (
        <li className="flex items-start gap-3 py-4">
            <span className="text-muted-foreground text-sm tabular-nums">#{index + 1}</span>
            <div className="flex min-w-0 flex-1 flex-col gap-2">
                <p className="text-base text-pretty sm:text-sm">{entry.message}</p>
                {ctx !== null && <pre className="bg-muted/50 overflow-x-auto rounded-lg p-4 font-mono text-sm">{ctxShown}</pre>}
                {ctxTruncated && (
                    <div>
                        <Button type="button" variant="link" size="sm" className="h-auto p-0" onClick={() => setOpen(!open)}>
                            {open ? 'Collapse' : 'Show full context'}
                        </Button>
                    </div>
                )}
            </div>
        </li>
    );
}

function Stat({ label, title, children }: { label: string; title?: string; children: ReactNode }) {
    return (
        <div className="flex min-w-0 flex-col gap-0.5">
            <dt className="text-muted-foreground truncate text-base sm:text-sm">{label}</dt>
            <dd title={title} className="truncate text-base tabular-nums sm:text-sm">
                {children}
            </dd>
        </div>
    );
}

export default function RunsShow({ run, errors, artifacts }: Props) {
    return (
        <AuthenticatedLayout>
            <Head title={`Run #${run.id}`} />
            <div className="flex flex-col gap-8">
                <div className="flex flex-col gap-3">
                    <Button asChild variant="link" size="sm" className="h-auto self-start p-0">
                        <Link href="/admin/runs">← All runs</Link>
                    </Button>
                    <PageHeader
                        title={`Run #${run.id}`}
                        actions={
                            <>
                                <RunStatusBadge status={run.status} />
                                <span className="text-muted-foreground text-base sm:text-sm">
                                    {kindLabel(run.source_kind)} · {run.source_label}
                                </span>
                            </>
                        }
                    />
                </div>

                <section className="flex flex-col gap-4">
                    <SectionLabel>Summary</SectionLabel>
                    <dl className="grid grid-cols-2 gap-x-6 gap-y-4 sm:grid-cols-4">
                        <Stat label="Started">{formatDateTime(run.started_at)}</Stat>
                        <Stat label="Finished">{formatDateTime(run.finished_at)}</Stat>
                        <Stat label="Duration">{formatDuration(run.duration_seconds)}</Stat>
                        <Stat label="Source" title={run.source}>
                            {run.source_label}
                        </Stat>
                        <Stat label="Fetched">{run.fetched}</Stat>
                        <Stat label="New">{run.new_count}</Stat>
                        <Stat label="Closed">{run.closed_count}</Stat>
                        <div className="flex min-w-0 flex-col gap-0.5">
                            <dt className="text-muted-foreground truncate text-base sm:text-sm">Errors</dt>
                            <dd
                                className={
                                    'truncate text-base tabular-nums sm:text-sm ' +
                                    (run.error_count > 0 ? 'text-rose-700 dark:text-rose-300' : '')
                                }
                            >
                                {run.error_count}
                            </dd>
                        </div>
                    </dl>
                </section>

                <section className="border-border flex flex-col gap-3 border-t pt-6">
                    <SectionLabel>
                        Errors (<span className="tabular-nums">{errors.length}</span>)
                    </SectionLabel>
                    {errors.length === 0 ? (
                        <p className="text-muted-foreground text-base text-pretty sm:text-sm">No errors recorded for this run.</p>
                    ) : (
                        <ul role="list" className="divide-border divide-y">
                            {errors.map((entry, idx) => (
                                <ErrorRow key={idx} entry={entry} index={idx} />
                            ))}
                        </ul>
                    )}
                </section>

                <section className="border-border flex flex-col gap-3 border-t pt-6">
                    <SectionLabel>
                        Artifacts (<span className="tabular-nums">{artifacts.length}</span>)
                    </SectionLabel>
                    {artifacts.length === 0 ? (
                        <p className="text-muted-foreground text-base text-pretty sm:text-sm">
                            No artifacts were captured for this run. ATS runs don&apos;t save artifacts.
                        </p>
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Fetched</TableHead>
                                    <TableHead>Connector</TableHead>
                                    <TableHead>Endpoint</TableHead>
                                    <TableHead className="text-right">HTTP</TableHead>
                                    <TableHead className="text-right">Size</TableHead>
                                    <TableHead>Retained until</TableHead>
                                    <TableHead>
                                        <span className="sr-only">Actions</span>
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {artifacts.map((artifact) => (
                                    <TableRow key={artifact.id} className={artifact.expired ? 'opacity-50' : ''}>
                                        <TableCell className="whitespace-nowrap tabular-nums">
                                            {artifact.fetched_at ? <RelativeDate iso={artifact.fetched_at} /> : '–'}
                                        </TableCell>
                                        <TableCell className="font-mono">{artifact.connector}</TableCell>
                                        <TableCell className="font-mono" title={artifact.request_endpoint}>
                                            {truncate(artifact.request_endpoint, 80)}
                                        </TableCell>
                                        <TableCell className="text-right font-mono tabular-nums">{artifact.http_status ?? '–'}</TableCell>
                                        <TableCell className="text-right tabular-nums">{formatBytes(artifact.size_bytes)}</TableCell>
                                        <TableCell className="whitespace-nowrap tabular-nums">
                                            {artifact.retained_until ? <RelativeDate iso={artifact.retained_until} /> : '–'}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {artifact.expired ? (
                                                <Badge variant="outline">expired</Badge>
                                            ) : artifact.download_url ? (
                                                <Button asChild variant="ghost" size="sm">
                                                    <a href={artifact.download_url}>Download</a>
                                                </Button>
                                            ) : (
                                                '–'
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
