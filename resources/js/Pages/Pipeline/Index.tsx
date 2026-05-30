import { Head, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PipelineColumn from '@/components/pipeline/PipelineColumn';
import { type PipelineRole } from '@/components/pipeline/PipelineCard';

interface StatusOption {
    value: string;
    label: string;
}

interface Props {
    columns: Record<string, PipelineRole[]>;
    statuses: StatusOption[];
    follow_up_threshold_days: number;
}

export default function PipelineIndexPage({ columns, statuses, follow_up_threshold_days }: Props) {
    const [dragging, setDragging] = useState<PipelineRole | null>(null);

    const totals = useMemo(() => {
        const total = Object.values(columns).reduce((sum, roles) => sum + roles.length, 0);
        const followUps = Object.values(columns).reduce(
            (sum, roles) => sum + roles.filter((r) => r.needs_follow_up).length,
            0,
        );
        return { total, followUps };
    }, [columns]);

    function handleDrop(status: string) {
        if (dragging === null || dragging.pipeline_status === status) {
            return;
        }

        router.post(
            '/pipeline/status',
            { role_id: dragging.id, pipeline_status: status },
            { preserveScroll: true },
        );
        setDragging(null);
    }

    return (
        <AuthenticatedLayout>
            <Head title="Pipeline" />

            <div className="text-foreground">
                <header className="mb-6 flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <h1 className="bg-gradient-to-r from-teal-600 to-emerald-500 bg-clip-text text-3xl font-semibold tracking-tight text-transparent dark:from-teal-400 dark:to-emerald-300">
                            Pipeline
                        </h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {totals.total > 0
                                ? `${totals.total} ${totals.total === 1 ? 'application' : 'applications'} in flight`
                                : 'No applications in the pipeline yet.'}
                            {totals.followUps > 0
                                ? ` · ${totals.followUps} need a follow-up after ${follow_up_threshold_days} days`
                                : ''}
                        </p>
                    </div>
                    <span className="rounded-full bg-teal-600 px-4 py-1.5 text-sm font-semibold text-white shadow-lg shadow-teal-600/25">
                        <span className="font-mono tabular-nums">{totals.total}</span> in pipeline
                    </span>
                </header>

                {totals.total === 0 ? (
                    <div className="rounded-2xl border border-dashed border-border px-6 py-16 text-center text-sm text-muted-foreground">
                        Add a role to the pipeline from a company page to start tracking it here.
                    </div>
                ) : (
                    <div className="flex gap-4 overflow-x-auto pb-4">
                        {statuses.map((status) => (
                            <PipelineColumn
                                key={status.value}
                                label={status.label}
                                status={status.value}
                                roles={columns[status.value] ?? []}
                                draggingId={dragging?.id ?? null}
                                onDragStart={setDragging}
                                onDragEnd={() => setDragging(null)}
                                onDrop={handleDrop}
                            />
                        ))}
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
