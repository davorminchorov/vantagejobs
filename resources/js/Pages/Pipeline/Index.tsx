import { Head, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/components/PageHeader';
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
                <PageHeader
                    title="Pipeline"
                    subtitle={
                        <>
                            {totals.total > 0
                                ? `${totals.total} ${totals.total === 1 ? 'application' : 'applications'} in flight`
                                : 'No applications in the pipeline yet.'}
                            {totals.followUps > 0
                                ? ` · ${totals.followUps} need a follow-up after ${follow_up_threshold_days} days`
                                : ''}
                        </>
                    }
                    actions={
                        <span className="text-sm text-muted-foreground">
                            <span className="font-mono tabular-nums">{totals.total}</span> in pipeline
                        </span>
                    }
                />

                {totals.total === 0 ? (
                    <div className="mt-6 rounded-2xl border border-dashed border-border px-6 py-16 text-center text-sm text-muted-foreground">
                        Add a role to the pipeline from a company page to start tracking it here.
                    </div>
                ) : (
                    <div className="relative mt-6">
                        <div className="flex snap-x gap-4 overflow-x-auto pb-4 [&>section]:snap-start">
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
                        <div
                            className="pointer-events-none absolute right-0 top-0 bottom-4 w-12 bg-gradient-to-l from-background to-transparent"
                            aria-hidden="true"
                        />
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
