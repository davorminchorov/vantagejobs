import type { FormEventHandler } from 'react';

import { Head, useForm } from '@inertiajs/react';

import SettingsLayout from '@/Layouts/SettingsLayout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatDateTime } from '@/lib/dates';

type ExportStatus = 'pending' | 'running' | 'completed' | 'failed' | 'expired';

interface ExportJob {
    id: number;
    status: ExportStatus;
    formats: string[];
    createdAt: string | null;
    expiresAt: string | null;
    schemaVersion: string | null;
    counts: Record<string, number> | null;
    error: string | null;
    downloadable: boolean;
}

interface Props {
    exports: ExportJob[];
}

const STATUS_VARIANT: Record<ExportStatus, 'secondary' | 'warning' | 'success' | 'destructive' | 'outline'> = {
    pending: 'secondary',
    running: 'warning',
    completed: 'success',
    failed: 'destructive',
    expired: 'outline',
};

const STATUS_LABEL: Record<ExportStatus, string> = {
    pending: 'Pending',
    running: 'Building',
    completed: 'Ready',
    failed: 'Failed',
    expired: 'Expired',
};

function totalRows(counts: Record<string, number> | null): number | null {
    if (!counts) {
        return null;
    }

    return Object.values(counts).reduce((sum, value) => sum + value, 0);
}

export default function DataPage({ exports }: Props) {
    const { post, processing, recentlySuccessful } = useForm({});

    const requestExport: FormEventHandler = (event) => {
        event.preventDefault();
        post('/settings/data/export', { preserveScroll: true });
    };

    return (
        <>
            <Head title="Data export" />
            <SettingsLayout
                title="Data export"
                description="Download a full copy of your data as a single archive containing a SQL dump, normalized JSON, and per-entity CSV files. Each bundle is available for 24 hours after it is built."
            >
                <div className="max-w-2xl space-y-6">
                    <form
                        onSubmit={requestExport}
                        className="space-y-4 rounded-2xl border border-border bg-card p-6 text-card-foreground shadow-sm"
                    >
                        <div className="space-y-1">
                            <h3 className="text-sm font-medium">Request an export</h3>
                            <p className="text-sm text-muted-foreground">
                                We build the archive in the background. It includes SQL, JSON, and CSV
                                copies of your companies, roles, contacts, events, and the rest of your
                                workspace, plus a manifest recording the schema version and row counts.
                            </p>
                        </div>
                        <div className="flex items-center gap-3">
                            <Button type="submit" disabled={processing}>
                                {processing ? 'Requesting…' : 'Request export'}
                            </Button>
                            {recentlySuccessful && (
                                <span className="text-sm text-muted-foreground">
                                    Queued. It will appear below shortly.
                                </span>
                            )}
                        </div>
                    </form>

                    <div className="rounded-2xl border border-border bg-card text-card-foreground shadow-sm">
                        <div className="border-b border-border px-6 py-4">
                            <h3 className="text-sm font-medium">Recent exports</h3>
                        </div>

                        {exports.length === 0 ? (
                            <p className="px-6 py-8 text-sm text-muted-foreground">
                                No exports yet. Request one above to get started.
                            </p>
                        ) : (
                            <ul className="divide-y divide-border">
                                {exports.map((job) => {
                                    const rows = totalRows(job.counts);

                                    return (
                                        <li
                                            key={job.id}
                                            className="flex flex-col gap-3 px-6 py-4 sm:flex-row sm:items-center sm:justify-between"
                                        >
                                            <div className="space-y-1">
                                                <div className="flex items-center gap-2">
                                                    <Badge variant={STATUS_VARIANT[job.status]}>
                                                        {STATUS_LABEL[job.status]}
                                                    </Badge>
                                                    <span className="text-sm font-medium">
                                                        Export #{job.id}
                                                    </span>
                                                </div>
                                                <p className="text-xs text-muted-foreground">
                                                    Requested {formatDateTime(job.createdAt)}
                                                    {job.status === 'completed' && job.expiresAt && (
                                                        <> · expires {formatDateTime(job.expiresAt)}</>
                                                    )}
                                                    {rows !== null && <> · {rows.toLocaleString()} rows</>}
                                                </p>
                                                {job.status === 'failed' && job.error && (
                                                    <p className="text-xs text-destructive">{job.error}</p>
                                                )}
                                            </div>

                                            <div className="shrink-0">
                                                {job.downloadable ? (
                                                    <Button asChild variant="secondary">
                                                        <a href={`/settings/data/${job.id}/download`}>
                                                            Download
                                                        </a>
                                                    </Button>
                                                ) : job.status === 'completed' ? (
                                                    <span className="text-xs text-muted-foreground">
                                                        Expired
                                                    </span>
                                                ) : null}
                                            </div>
                                        </li>
                                    );
                                })}
                            </ul>
                        )}
                    </div>
                </div>
            </SettingsLayout>
        </>
    );
}
