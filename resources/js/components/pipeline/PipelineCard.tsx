import { Link } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { formatDate } from '@/lib/dates';
import { cn } from '@/lib/utils';

export interface PipelineRole {
    id: number;
    title: string;
    company_id: number;
    company_name: string | null;
    pipeline_status: string;
    applied_at: string | null;
    next_action: string | null;
    next_action_date: string | null;
    needs_follow_up: boolean;
}

interface Props {
    role: PipelineRole;
    dragging: boolean;
    onDragStart: (role: PipelineRole) => void;
    onDragEnd: () => void;
}

export default function PipelineCard({ role, dragging, onDragStart, onDragEnd }: Props) {
    return (
        <article
            draggable
            onDragStart={() => onDragStart(role)}
            onDragEnd={onDragEnd}
            aria-label={`${role.title}${role.company_name ? ` at ${role.company_name}` : ''}`}
            className={cn(
                'group cursor-grab rounded-xl border border-border bg-background p-3 shadow-sm transition',
                'hover:border-teal-500/60 hover:shadow-md active:cursor-grabbing',
                dragging && 'opacity-40',
            )}
        >
            <div className="flex items-start justify-between gap-2">
                <Link
                    href={`/pipeline/roles/${role.id}`}
                    className="text-sm font-medium leading-snug hover:underline"
                >
                    {role.title}
                </Link>
                {role.needs_follow_up ? (
                    <Badge variant="warning" className="shrink-0" title="No activity past the follow-up threshold">
                        Follow up
                    </Badge>
                ) : null}
            </div>

            {role.company_name ? (
                <Link
                    href={`/companies/${role.company_id}`}
                    className="mt-1 block text-xs text-muted-foreground hover:underline"
                >
                    {role.company_name}
                </Link>
            ) : null}

            {role.next_action ? (
                <p className="mt-2 line-clamp-2 text-xs text-muted-foreground">
                    <span className="font-medium text-foreground">Next:</span> {role.next_action}
                    {role.next_action_date ? (
                        <span className="ml-1 font-mono tabular-nums">({formatDate(role.next_action_date)})</span>
                    ) : null}
                </p>
            ) : null}

            {role.applied_at ? (
                <p className="mt-2 font-mono text-[11px] tabular-nums text-muted-foreground">
                    Applied {formatDate(role.applied_at)}
                </p>
            ) : null}
        </article>
    );
}
