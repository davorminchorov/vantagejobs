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
                'cursor-grab rounded-lg border border-border bg-card p-3 active:cursor-grabbing',
                dragging && 'opacity-40 shadow-lg ring-1 ring-black/5 dark:shadow-none',
            )}
        >
            <div className="flex items-start justify-between gap-2">
                <Link
                    href={`/pipeline/roles/${role.id}`}
                    className="min-w-0 text-base font-medium text-balance hover:underline sm:text-sm"
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
                    className="mt-1 block truncate text-sm text-muted-foreground hover:underline sm:text-xs"
                >
                    {role.company_name}
                </Link>
            ) : null}

            {role.next_action ? (
                <p className="mt-2 line-clamp-2 text-sm text-pretty text-muted-foreground sm:text-xs">
                    <span className="font-medium text-foreground">Next:</span> {role.next_action}
                    {role.next_action_date ? (
                        <span className="ml-1 tabular-nums">({formatDate(role.next_action_date)})</span>
                    ) : null}
                </p>
            ) : null}

            {role.applied_at ? (
                <p className="mt-2 text-xs tabular-nums text-muted-foreground">
                    Applied {formatDate(role.applied_at)}
                </p>
            ) : null}
        </article>
    );
}
