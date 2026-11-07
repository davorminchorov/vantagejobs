import { Head, InfiniteScroll, router } from '@inertiajs/react';
import { X } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import type { FormEvent } from 'react';

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import BulkActionBar from '@/components/bulk/BulkActionBar';
import PageHeader from '@/components/PageHeader';
import { CompanyRoleGroup } from '@/components/roles/CompanyRoleGroup';
import { rolePostingUrl } from '@/components/roles/helpers';
import type { GroupPaginator, RoleFilters, RoleGroup, RoleItem, RoleOptions } from '@/components/roles/types';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { cn } from '@/lib/utils';

interface Props {
    groups: GroupPaginator;
    filters: RoleFilters;
    counts: { open: number; dismissed: number };
    options: RoleOptions;
}

const WEEK_MS = 7 * 24 * 60 * 60 * 1000;

function filtersToQuery(filters: RoleFilters): Record<string, string | number> {
    const query: Record<string, string | number> = {};
    if (filters.status !== 'open') query.status = filters.status;
    if (filters.q) query.q = filters.q;
    if (filters.tier) query.tier = filters.tier;
    if (filters.workplace) query.workplace = filters.workplace;
    if (filters.posted) query.posted = filters.posted;
    if (filters.company !== null) query.company = filters.company;
    return query;
}

function hrefFor(filters: RoleFilters): string {
    const params = new URLSearchParams(
        Object.entries(filtersToQuery(filters)).map(([key, value]) => [key, String(value)]),
    ).toString();
    return params === '' ? '/roles' : `/roles?${params}`;
}

/** Drops roles from the merged `groups` prop and removes companies left with nothing to show. */
function withoutRoles(old: unknown, ids: number[]): GroupPaginator {
    const paginator = old as GroupPaginator;
    const removed = new Set(ids);
    const now = Date.now();
    const data = paginator.data
        .map((group): RoleGroup => {
            const gone = group.roles.filter((role) => removed.has(role.id));
            const goneNew = gone.filter((role) => now - new Date(role.created_at).getTime() < WEEK_MS).length;
            const remainingIds = group.role_ids.filter((id) => !removed.has(id));
            return {
                ...group,
                roles: group.roles.filter((role) => !removed.has(role.id)),
                role_ids: remainingIds,
                total_matching: remainingIds.length,
                new_this_week: Math.max(0, group.new_this_week - goneNew),
            };
        })
        .filter((group) => group.role_ids.length > 0);
    return { ...paginator, data };
}

export default function RolesIndex({ groups, filters, counts, options }: Props) {
    const [expandedIds, setExpandedIds] = useState<number[]>([]);
    const [focusedRoleId, setFocusedRoleId] = useState<number | null>(null);
    const itemsRef = useRef<HTMLDivElement | null>(null);
    const roleRefs = useRef(new Map<number, HTMLLIElement>());
    const dismissedView = filters.status === 'dismissed';

    const flatRoles = useMemo(() => groups.data.flatMap((group) => group.roles), [groups.data]);
    const companyName =
        filters.company !== null ? groups.data.find((group) => group.company.id === filters.company)?.company.name : null;

    function applyFilters(patch: Partial<RoleFilters>) {
        setExpandedIds([]);
        setFocusedRoleId(null);
        router.get('/roles', filtersToQuery({ ...filters, ...patch }), {
            replace: true,
            preserveState: true,
            only: ['groups', 'filters', 'counts'],
            reset: ['groups'],
        });
    }

    function toggle(role: RoleItem) {
        setFocusedRoleId(role.id);
        setExpandedIds((prev) => (prev.includes(role.id) ? prev.filter((id) => id !== role.id) : [...prev, role.id]));
    }

    /** Moves focus to the role after the removed ones so keyboard triage can continue. */
    function focusAfterRemoval(ids: number[]) {
        const index = flatRoles.findIndex((role) => role.id === focusedRoleId);
        if (index < 0 || !ids.includes(focusedRoleId as number)) {
            return;
        }
        const next = flatRoles.slice(index + 1).find((role) => !ids.includes(role.id))
            ?? flatRoles.slice(0, index).reverse().find((role) => !ids.includes(role.id));
        setFocusedRoleId(next?.id ?? null);
    }

    function dismiss(ids: number[]) {
        if (ids.length === 0) {
            return;
        }
        router.post(
            '/bulk-actions',
            { entity: 'role', action: 'dismiss', ids },
            {
                preserveScroll: true,
                preserveState: true,
                only: ['counts', 'flash'],
                onSuccess: () => {
                    focusAfterRemoval(ids);
                    router.replaceProp('groups', (old: unknown) => withoutRoles(old, ids));
                    setExpandedIds((prev) => prev.filter((id) => !ids.includes(id)));
                },
            },
        );
    }

    function restore(role: RoleItem) {
        router.post(
            `/roles/${role.id}/restore`,
            {},
            {
                preserveScroll: true,
                preserveState: true,
                only: ['counts'],
                onSuccess: () => {
                    focusAfterRemoval([role.id]);
                    router.replaceProp('groups', (old: unknown) => withoutRoles(old, [role.id]));
                },
            },
        );
    }

    function addToPipeline(role: RoleItem) {
        router.post(
            `/pipeline/roles/${role.id}/add`,
            {},
            {
                preserveScroll: true,
                preserveState: true,
                only: ['counts'],
                onSuccess: () => {
                    router.replaceProp('groups', (old: unknown) => {
                        const paginator = old as GroupPaginator;
                        return {
                            ...paginator,
                            data: paginator.data.map((group) => ({
                                ...group,
                                roles: group.roles.map((r) =>
                                    r.id === role.id ? { ...r, pipeline_status: r.pipeline_status ?? 'researching' } : r,
                                ),
                            })),
                        };
                    });
                },
            },
        );
    }

    useEffect(() => {
        if (focusedRoleId === null) {
            return;
        }
        const el = roleRefs.current.get(focusedRoleId);
        if (!el) {
            return;
        }
        const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        el.scrollIntoView({ block: 'nearest', behavior: reduceMotion ? 'auto' : 'smooth' });
    }, [focusedRoleId]);

    useEffect(() => {
        function onKey(e: KeyboardEvent) {
            const target = e.target as HTMLElement | null;
            if (
                e.metaKey ||
                e.ctrlKey ||
                e.altKey ||
                (target &&
                    (target.tagName === 'INPUT' ||
                        target.tagName === 'TEXTAREA' ||
                        target.tagName === 'SELECT' ||
                        target.isContentEditable ||
                        target.closest('[role="dialog"], [role="menu"]') !== null))
            ) {
                return;
            }
            if (flatRoles.length === 0) {
                return;
            }

            const index = flatRoles.findIndex((role) => role.id === focusedRoleId);
            if (e.key === 'j' || e.key === 'ArrowDown') {
                e.preventDefault();
                setFocusedRoleId(flatRoles[Math.min(flatRoles.length - 1, index + 1)]?.id ?? null);
                return;
            }
            if (e.key === 'k' || e.key === 'ArrowUp') {
                e.preventDefault();
                setFocusedRoleId(flatRoles[Math.max(0, index - 1)]?.id ?? null);
                return;
            }

            const role = index >= 0 ? flatRoles[index] : undefined;
            if (!role) {
                return;
            }
            if (e.key === 'Enter' || e.key === 'e') {
                if (target?.tagName === 'BUTTON' || target?.tagName === 'A') {
                    return;
                }
                e.preventDefault();
                toggle(role);
            } else if (e.key === 'x' && !dismissedView) {
                e.preventDefault();
                dismiss([role.id]);
            } else if (e.key === 'o') {
                const url = rolePostingUrl(role);
                if (url) {
                    e.preventDefault();
                    window.open(url, '_blank', 'noopener,noreferrer');
                }
            } else if (e.key === 'p' && role.pipeline_status === null) {
                e.preventDefault();
                addToPipeline(role);
            } else if (e.key === 'Escape') {
                setExpandedIds([]);
            }
        }

        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
        // The handlers only read state covered by these deps.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [flatRoles, focusedRoleId, dismissedView]);

    function onSearchSubmit(e: FormEvent<HTMLFormElement>) {
        e.preventDefault();
        const form = new FormData(e.currentTarget);
        applyFilters({ q: ((form.get('q') as string) ?? '').trim() || null });
    }

    const registerRole = (roleId: number) => (el: HTMLLIElement | null) => {
        if (el) {
            roleRefs.current.set(roleId, el);
        } else {
            roleRefs.current.delete(roleId);
        }
    };

    const hasFilters =
        filters.q !== null || filters.tier !== null || filters.workplace !== null || filters.posted !== null || filters.company !== null;

    return (
        <AuthenticatedLayout>
            <Head title="Roles" />
            <div className="flex flex-col gap-6">
                <PageHeader
                    title="Roles"
                    subtitle={
                        <>
                            Open roles at the companies you watch, grouped by company
                            <span className="hidden md:inline"> · J/K move · Enter expand · X dismiss · P add to applications · O open</span>
                        </>
                    }
                    actions={
                        <form onSubmit={onSearchSubmit} className="flex w-full gap-2 sm:w-auto" role="search">
                            <Input
                                key={filters.q ?? ''}
                                name="q"
                                aria-label="Search role titles"
                                defaultValue={filters.q ?? ''}
                                placeholder="Search role titles"
                                className="w-full min-w-0 sm:w-64"
                            />
                            <Button type="submit" variant="outline" className="shrink-0">
                                Search
                            </Button>
                        </form>
                    }
                />

                <div className="flex flex-col gap-3">
                    <div
                        role="tablist"
                        aria-label="Role status"
                        className="flex items-center gap-1 overflow-x-auto shadow-[inset_0_-1px_0_var(--border)]"
                    >
                        {(['open', 'dismissed'] as const).map((status) => {
                            const active = filters.status === status;
                            return (
                                <button
                                    key={status}
                                    type="button"
                                    role="tab"
                                    aria-selected={active}
                                    onClick={() => applyFilters({ status })}
                                    className={cn(
                                        'min-h-10 shrink-0 border-b-2 px-3 text-base font-medium whitespace-nowrap sm:text-sm',
                                        active
                                            ? 'border-primary text-foreground'
                                            : 'border-transparent text-muted-foreground hover:text-foreground',
                                    )}
                                >
                                    {status === 'open' ? 'Open' : 'Dismissed'}{' '}
                                    <span className="text-muted-foreground tabular-nums">
                                        {(status === 'open' ? counts.open : counts.dismissed).toLocaleString()}
                                    </span>
                                </button>
                            );
                        })}
                    </div>

                    <div className="-mx-4 flex items-center gap-x-5 gap-y-3 overflow-x-auto px-4 text-base whitespace-nowrap *:shrink-0 sm:mx-0 sm:flex-wrap sm:overflow-visible sm:px-0 sm:text-sm">
                        <label className="flex items-center gap-2">
                            <span className="text-muted-foreground">Tier</span>
                            <NativeSelect
                                name="tier"
                                value={filters.tier ?? ''}
                                onChange={(e) => applyFilters({ tier: (e.currentTarget.value || null) as RoleFilters['tier'] })}
                            >
                                <option value="">Any</option>
                                {options.tiers.map((tier) => (
                                    <option key={tier} value={tier}>
                                        Tier {tier}
                                    </option>
                                ))}
                            </NativeSelect>
                        </label>
                        <label className="flex items-center gap-2">
                            <span className="text-muted-foreground">Workplace</span>
                            <NativeSelect
                                name="workplace"
                                value={filters.workplace ?? ''}
                                onChange={(e) =>
                                    applyFilters({ workplace: (e.currentTarget.value || null) as RoleFilters['workplace'] })
                                }
                            >
                                <option value="">Any</option>
                                {options.workplaces.map((w) => (
                                    <option key={w.value} value={w.value}>
                                        {w.label}
                                    </option>
                                ))}
                            </NativeSelect>
                        </label>
                        <label className="flex items-center gap-2">
                            <span className="text-muted-foreground">Posted</span>
                            <NativeSelect
                                name="posted"
                                value={filters.posted ?? ''}
                                onChange={(e) => applyFilters({ posted: (e.currentTarget.value || null) as RoleFilters['posted'] })}
                            >
                                <option value="">Any time</option>
                                {options.posted.map((p) => (
                                    <option key={p.value} value={p.value}>
                                        {p.label}
                                    </option>
                                ))}
                            </NativeSelect>
                        </label>
                        {filters.company !== null ? (
                            <span className="inline-flex items-center gap-1 rounded-md bg-muted py-1 pr-1 pl-2.5">
                                {companyName ?? 'One company'}
                                <button
                                    type="button"
                                    onClick={() => applyFilters({ company: null })}
                                    aria-label="Show all companies"
                                    className="rounded p-0.5 text-muted-foreground hover:bg-background hover:text-foreground focus-visible:outline-2 focus-visible:outline-ring"
                                >
                                    <X aria-hidden="true" className="size-3.5" />
                                </button>
                            </span>
                        ) : null}
                        {hasFilters ? (
                            <button
                                type="button"
                                onClick={() => applyFilters({ q: null, tier: null, workplace: null, posted: null, company: null })}
                                className="min-h-10 rounded-md text-muted-foreground underline-offset-4 hover:text-foreground hover:underline focus-visible:outline-2 focus-visible:outline-ring sm:min-h-0"
                            >
                                Clear filters
                            </button>
                        ) : null}
                    </div>
                </div>

                {groups.data.length === 0 && groups.next_page_url === null ? (
                    <EmptyState className="py-16">
                        {dismissedView
                            ? 'Nothing dismissed matches these filters.'
                            : hasFilters
                              ? 'No open roles match these filters.'
                              : 'No open roles right now. New roles show up here after the next scrape.'}
                    </EmptyState>
                ) : (
                    <div>
                        <InfiniteScroll
                            data="groups"
                            manual
                            itemsElement={itemsRef}
                            next={({ loading, fetch, hasMore }) =>
                                hasMore ? (
                                    <div className="flex justify-center pt-6">
                                        <Button variant="outline" onClick={fetch} disabled={loading}>
                                            {loading ? 'Loading…' : 'Load more companies'}
                                        </Button>
                                    </div>
                                ) : null
                            }
                        >
                            <div ref={itemsRef} className="flex flex-col gap-4">
                                {groups.data.map((group) => (
                                    <CompanyRoleGroup
                                        key={group.company.id}
                                        group={group}
                                        dismissedView={dismissedView}
                                        expandedIds={expandedIds}
                                        focusedRoleId={focusedRoleId}
                                        onToggle={toggle}
                                        onDismiss={(role) => dismiss([role.id])}
                                        onRestore={restore}
                                        onAddToPipeline={addToPipeline}
                                        onDismissAll={(g) => dismiss(g.role_ids)}
                                        registerRole={registerRole}
                                        showAllHref={hrefFor({ ...filters, company: group.company.id })}
                                    />
                                ))}
                            </div>
                        </InfiniteScroll>
                    </div>
                )}

                <BulkActionBar entity="role" selectedIds={[]} onClear={() => undefined} />
            </div>
        </AuthenticatedLayout>
    );
}
