import { Link } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

import { RoleLine } from './RoleLine';
import type { RoleGroup, RoleItem } from './types';

export interface CompanyRoleGroupProps {
    group: RoleGroup;
    dismissedView: boolean;
    expandedIds: number[];
    focusedRoleId: number | null;
    onToggle: (role: RoleItem) => void;
    onDismiss: (role: RoleItem) => void;
    onRestore: (role: RoleItem) => void;
    onAddToPipeline: (role: RoleItem) => void;
    onDismissAll: (group: RoleGroup) => void;
    registerRole: (roleId: number) => (el: HTMLLIElement | null) => void;
    /** Link to the same view narrowed to this company, which lists all of its roles. */
    showAllHref: string;
}

/** A watched company and its matching open roles, newest first. */
export function CompanyRoleGroup({
    group,
    dismissedView,
    expandedIds,
    focusedRoleId,
    onToggle,
    onDismiss,
    onRestore,
    onAddToPipeline,
    onDismissAll,
    registerRole,
    showAllHref,
}: CompanyRoleGroupProps) {
    const { company } = group;
    const hidden = group.total_matching - group.roles.length;

    return (
        <section aria-labelledby={`company-${company.id}-heading`} className="rounded-xl border border-border">
            <header className="flex flex-wrap items-center gap-x-3 gap-y-2 border-b border-border px-3 py-3 sm:px-4">
                <div className="flex min-w-0 flex-1 flex-wrap items-center gap-x-3 gap-y-1">
                    <h2 id={`company-${company.id}-heading`} className="min-w-0 text-base font-semibold">
                        <Link href={`/companies/${company.id}`} className="hover:underline">
                            {company.name}
                        </Link>
                    </h2>
                    <Badge variant={company.tier === 'A' ? 'default' : company.tier === 'B' ? 'secondary' : 'outline'}>
                        Tier {company.tier}
                    </Badge>
                    <span className="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-muted-foreground">
                        {company.quality_score !== null ? (
                            <span title="Quality score">
                                Quality <span className="font-medium text-foreground tabular-nums">{company.quality_score}</span>
                            </span>
                        ) : null}
                        {company.funded_recently ? <span>Raised in the last 18 months</span> : null}
                        <span className="tabular-nums">
                            {group.total_matching} {group.total_matching === 1 ? 'role' : 'roles'}
                            {group.new_this_week > 0 ? (
                                <>
                                    {' · '}
                                    <span className="font-medium text-primary">{group.new_this_week} new this week</span>
                                </>
                            ) : null}
                        </span>
                    </span>
                </div>
                {!dismissedView ? (
                    <Button type="button" size="sm" variant="outline" onClick={() => onDismissAll(group)}>
                        Dismiss all{group.total_matching > 1 ? ` ${group.total_matching}` : ''}
                    </Button>
                ) : null}
            </header>

            <ul role="list" className="divide-y divide-border">
                {group.roles.map((role) => (
                    <RoleLine
                        key={role.id}
                        ref={registerRole(role.id)}
                        role={role}
                        expanded={expandedIds.includes(role.id)}
                        focused={focusedRoleId === role.id}
                        dismissedView={dismissedView}
                        onToggle={() => onToggle(role)}
                        onDismiss={() => onDismiss(role)}
                        onRestore={() => onRestore(role)}
                        onAddToPipeline={() => onAddToPipeline(role)}
                    />
                ))}
            </ul>

            {hidden > 0 ? (
                <div className="border-t border-border px-3 py-2.5 text-sm sm:px-4">
                    <Link href={showAllHref} className="font-medium text-primary hover:underline">
                        Show all {group.total_matching} roles at {company.name}
                    </Link>
                </div>
            ) : null}
        </section>
    );
}
