import { Link } from '@inertiajs/react';
import { ChevronRight, RotateCcw, X } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { SectionLabel } from '@/components/ui/section-label';
import { formatRelative } from '@/lib/dates';
import { cn } from '@/lib/utils';

import { roleDate, rolePayLabel, rolePostingUrl, sourceLabel, workplaceLabel } from './helpers';
import type { RoleItem } from './types';
import { useRoleDescription } from './useRoleDescription';

export interface RoleLineProps {
    role: RoleItem;
    expanded: boolean;
    focused: boolean;
    dismissedView: boolean;
    /** The company closed this role; it can't be dismissed or added to applications. */
    closed?: boolean;
    onToggle: () => void;
    onDismiss: () => void;
    onRestore: () => void;
    onAddToPipeline: () => void;
    ref?: React.Ref<HTMLLIElement>;
}

/** One role inside a company group: a single scannable line that expands into details and the description. */
export function RoleLine({
    role,
    expanded,
    focused,
    dismissedView,
    closed = false,
    onToggle,
    onDismiss,
    onRestore,
    onAddToPipeline,
    ref,
}: RoleLineProps) {
    const workplace = workplaceLabel(role);
    const pay = rolePayLabel(role);
    const where = [workplace, role.location].filter(Boolean).join(' · ');
    const detailsId = `role-${role.id}-details`;

    return (
        <li ref={ref} className="group/role">
            <div className={cn('flex items-center gap-2 px-3 sm:px-4', focused ? 'bg-muted/70' : 'hover:bg-muted/40')}>
                <button
                    type="button"
                    onClick={onToggle}
                    aria-expanded={expanded}
                    aria-controls={detailsId}
                    className="flex min-w-0 flex-1 items-center gap-2 py-2.5 text-left outline-none focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring"
                >
                    <ChevronRight
                        aria-hidden="true"
                        className={cn('size-4 shrink-0 text-muted-foreground transition-transform', expanded && 'rotate-90')}
                    />
                    <span className="min-w-0 flex-1">
                        <span className="block truncate text-base font-medium sm:text-sm">{role.title}</span>
                        <span className="block truncate text-sm text-muted-foreground sm:hidden">
                            {[where, pay].filter(Boolean).join(' · ') || sourceLabel(role.source)}
                        </span>
                    </span>
                    <span className="hidden w-48 shrink-0 truncate text-sm text-muted-foreground sm:block">{where}</span>
                    <span className="hidden w-32 shrink-0 truncate text-sm text-muted-foreground tabular-nums md:block">
                        {pay ?? ''}
                    </span>
                    {closed ? (
                        <Badge variant="outline" className="shrink-0">
                            Closed
                        </Badge>
                    ) : null}
                    {role.pipeline_status !== null ? (
                        <Badge variant="success" className="shrink-0">
                            In applications
                        </Badge>
                    ) : null}
                    <span className="w-20 shrink-0 text-right text-xs text-muted-foreground tabular-nums">
                        {formatRelative(roleDate(role))}
                    </span>
                </button>
                {closed ? (
                    <span className="size-10 shrink-0 sm:size-9" aria-hidden="true" />
                ) : dismissedView ? (
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        onClick={onRestore}
                        aria-label={`Restore ${role.title}`}
                        title="Restore"
                        className="shrink-0"
                    >
                        <RotateCcw aria-hidden="true" />
                    </Button>
                ) : (
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        onClick={onDismiss}
                        aria-label={`Dismiss ${role.title}`}
                        title="Dismiss (X)"
                        className="shrink-0 text-muted-foreground hover:text-foreground"
                    >
                        <X aria-hidden="true" />
                    </Button>
                )}
            </div>

            {expanded ? (
                <RoleDetails
                    id={detailsId}
                    role={role}
                    where={where}
                    pay={pay}
                    closed={closed}
                    onAddToPipeline={onAddToPipeline}
                />
            ) : null}
        </li>
    );
}

function RoleDetails({
    id,
    role,
    where,
    pay,
    closed,
    onAddToPipeline,
}: {
    id: string;
    role: RoleItem;
    where: string;
    pay: string | null;
    closed: boolean;
    onAddToPipeline: () => void;
}) {
    const description = useRoleDescription(role.id, role.has_description);
    const url = rolePostingUrl(role);
    const team = [role.department, role.team].filter(Boolean).join(' / ');

    return (
        <div id={id} className="flex flex-col gap-4 px-3 pt-1 pb-5 sm:pl-10 sm:pr-4">
            <dl className="flex flex-wrap gap-x-6 gap-y-1.5 text-base sm:text-sm">
                <Fact label="Where" value={where || 'Not stated'} />
                <Fact label="Pay" value={pay ?? 'Not stated'} />
                {team ? <Fact label="Team" value={team} /> : null}
                <Fact label="Source" value={sourceLabel(role.source)} />
            </dl>

            <div className="flex flex-wrap items-center gap-2">
                {url ? (
                    <Button asChild size="sm" variant="outline">
                        <a href={url} target="_blank" rel="noopener noreferrer">
                            Open posting ↗
                        </a>
                    </Button>
                ) : null}
                {closed && role.pipeline_status === null ? null : role.pipeline_status === null ? (
                    <Button type="button" size="sm" onClick={onAddToPipeline}>
                        Add to applications
                    </Button>
                ) : (
                    <Button asChild size="sm" variant="outline">
                        <Link href={`/applications?role=${role.id}`}>View application</Link>
                    </Button>
                )}
            </div>

            <div className="flex flex-col gap-2">
                <SectionLabel>Description</SectionLabel>
                {!role.has_description ? (
                    <p className="max-w-prose text-base text-pretty text-muted-foreground sm:text-sm">
                        {sourceLabel(role.source)} doesn&apos;t share the job description with its listings. Open the
                        posting to read it.
                    </p>
                ) : description.status === 'loaded' ? (
                    description.text ? (
                        <div className="max-h-[32rem] max-w-prose overflow-y-auto text-base/7 break-words whitespace-pre-line text-pretty sm:text-sm/6">
                            {description.text}
                        </div>
                    ) : (
                        <p className="text-base text-muted-foreground sm:text-sm">This posting has no description text.</p>
                    )
                ) : description.status === 'error' ? (
                    <p className="text-base text-destructive sm:text-sm">Couldn&apos;t load the description. Collapse and try again.</p>
                ) : (
                    <div className="flex max-w-prose flex-col gap-2" aria-busy="true" aria-label="Loading description">
                        <div className="h-3 w-full animate-pulse rounded bg-muted" />
                        <div className="h-3 w-11/12 animate-pulse rounded bg-muted" />
                        <div className="h-3 w-4/5 animate-pulse rounded bg-muted" />
                    </div>
                )}
            </div>
        </div>
    );
}

function Fact({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex gap-1.5">
            <dt className="text-muted-foreground">{label}</dt>
            <dd>{value}</dd>
        </div>
    );
}
