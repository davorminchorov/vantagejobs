import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import PipelineCard, { type PipelineRole } from '@/components/pipeline/PipelineCard';
import { cn } from '@/lib/utils';

interface Props {
    label: string;
    status: string;
    roles: PipelineRole[];
    draggingId: number | null;
    onDragStart: (role: PipelineRole) => void;
    onDragEnd: () => void;
    onDrop: (status: string) => void;
}

export default function PipelineColumn({
    label,
    status,
    roles,
    draggingId,
    onDragStart,
    onDragEnd,
    onDrop,
}: Props) {
    const [isOver, setIsOver] = useState(false);
    const draggingRole = draggingId !== null;
    const isSameColumn = roles.some((r) => r.id === draggingId);

    return (
        <section
            aria-label={`${label} (${roles.length})`}
            onDragOver={(e) => {
                if (draggingRole) {
                    e.preventDefault();
                    setIsOver(true);
                }
            }}
            onDragLeave={() => setIsOver(false)}
            onDrop={(e) => {
                e.preventDefault();
                setIsOver(false);
                if (draggingRole && !isSameColumn) {
                    onDrop(status);
                }
            }}
            className={cn(
                'flex w-72 shrink-0 flex-col rounded-2xl border border-border bg-muted/30 transition',
                isOver && draggingRole && !isSameColumn && 'border-teal-500 bg-teal-500/5 ring-2 ring-teal-500/30',
            )}
        >
            <header className="flex items-center justify-between gap-2 border-b border-border px-3 py-2.5">
                <h2 className="text-sm font-semibold tracking-tight">{label}</h2>
                <Badge variant="outline" className="font-mono tabular-nums">
                    {roles.length}
                </Badge>
            </header>

            <div className="flex flex-1 flex-col gap-2 p-2">
                {roles.length === 0 ? (
                    <p className="px-2 py-6 text-center text-xs text-muted-foreground">
                        {isOver && draggingRole && !isSameColumn ? 'Drop to move here' : 'No roles'}
                    </p>
                ) : (
                    roles.map((role) => (
                        <PipelineCard
                            key={role.id}
                            role={role}
                            dragging={role.id === draggingId}
                            onDragStart={onDragStart}
                            onDragEnd={onDragEnd}
                        />
                    ))
                )}
            </div>
        </section>
    );
}
