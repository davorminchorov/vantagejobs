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
                'flex w-72 shrink-0 flex-col rounded-xl bg-muted/50',
                isOver && draggingRole && !isSameColumn && 'bg-accent ring-2 ring-primary/30',
            )}
        >
            <header className="flex items-center justify-between gap-2 px-3 pt-3 pb-1">
                <h2 className="text-base font-semibold text-balance sm:text-sm">{label}</h2>
                <Badge variant="secondary" className="tabular-nums">
                    {roles.length}
                </Badge>
            </header>

            <div className="flex flex-1 flex-col gap-2 p-2">
                {roles.length === 0 ? (
                    <p className="px-2 py-6 text-center text-sm text-muted-foreground sm:text-xs">
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
