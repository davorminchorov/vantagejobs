import type { FormEventHandler } from 'react';

import { Head, useForm } from '@inertiajs/react';

import SettingsLayout, { SettingsSection } from '@/Layouts/SettingsLayout';
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
                <SettingsSection
                    title="Request an export"
                    description="We build the archive in the background. It includes SQL, JSON, and CSV copies of your companies, roles, contacts, events, and the rest of your workspace, plus a manifest recording the schema version and row counts."
                >
                    <form onSubmit={requestExport} className="flex items-center gap-3">
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Requesting…' : 'Request export'}
                        </Button>
                        {recentlySuccessful && (
                            <span className="text-sm text-muted-foreground">
                                Queued. It will appear below shortly.
                            </span>
                        )}
                    </form>
                </SettingsSection>

                <SettingsSection title="Recent exports">
                    {exports.length === 0 ? (
                        <p className="text-base text-pretty text-muted-foreground sm:text-sm">
                            No exports yet. Request one above to get started.
                        </p>
                    ) : (
                        <ul role="list" className="divide-y divide-border border-y border-border">
                            {exports.map((job) => {
                                const rows = totalRows(job.counts);

                                return (
                                    <li
                                        key={job.id}
                                        className="flex flex-col gap-3 py-4 sm:flex-row sm:items-center sm:justify-between"
                                    >
                                        <div className="flex min-w-0 flex-col gap-1">
                                            <div className="flex items-center gap-2">
                                                <Badge variant={STATUS_VARIANT[job.status]}>
                                                    {STATUS_LABEL[job.status]}
                                                </Badge>
                                                <span className="text-base font-medium sm:text-sm">
                                                    Export #{job.id}
                                                </span>
                                            </div>
                                            <p className="text-sm text-muted-foreground">
                                                Requested {formatDateTime(job.createdAt)}
                                                {job.status === 'completed' && job.expiresAt && (
                                                    <> · expires {formatDateTime(job.expiresAt)}</>
                                                )}
                                                {rows !== null && <> · {rows.toLocaleString()} rows</>}
                                            </p>
                                            {job.status === 'failed' && job.error && (
                                                <p className="text-sm text-destructive">{job.error}</p>
                                            )}
                                        </div>

                                        <div className="shrink-0">
                                            {job.downloadable ? (
                                                <Button asChild variant="outline" size="sm">
                                                    <a href={`/settings/data/${job.id}/download`}>Download</a>
                                                </Button>
                                            ) : job.status === 'completed' ? (
                                                <span className="text-sm text-muted-foreground">Expired</span>
                                            ) : null}
                                        </div>
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </SettingsSection>
            </SettingsLayout>
        </>
    );
}
