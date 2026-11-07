import { Head, Link, router, useForm } from '@inertiajs/react';
import { ChevronDown } from 'lucide-react';
import type { FormEvent, ReactNode } from 'react';
import { useState } from 'react';

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import HiringTrendChart from '@/components/charts/HiringTrendChart';
import TagInput, { type TagSummary } from '@/components/companies/TagInput';
import { RoleLine } from '@/components/roles/RoleLine';
import type { RoleItem } from '@/components/roles/types';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { SectionLabel } from '@/components/ui/section-label';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { formatDate, formatDateTime, RelativeDate } from '@/lib/dates';
import { cn } from '@/lib/utils';

interface CompanyDetail {
    id: number;
    name: string;
    website: string | null;
    careers_url: string | null;
    domain: string | null;
    ats_type: string | null;
    ats_slug: string | null;
    ats_status: string;
    tier: 'A' | 'B' | 'C' | 'graveyard';
    status: string;
    is_active: boolean;
    last_checked_at: string | null;
    etag: string | null;
    last_modified: string | null;
    created_at: string | null;
    funding_stage: string | null;
    funding_last_raised_at: string | null;
    funding_last_amount_usd: number | null;
    github_org: string | null;
    eng_blog_rss_url: string | null;
}

interface GraveyardInfo {
    demoted_at: string | null;
    reason: string | null;
    tags: string[];
}

type RoleRow = RoleItem & { is_open: boolean };

interface PaginatedRoles {
    data: RoleRow[];
    current_page: number;
    last_page: number;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
}

type RoleState = 'open' | 'dismissed' | 'closed' | 'all';

interface RolesFilters {
    q: string | null;
    state: RoleState;
}

interface RoleCounts {
    open: number;
    dismissed: number;
    closed: number;
}

interface TimelineItem {
    kind: 'event' | 'note';
    id: string;
    type: string;
    title: string;
    body: string | null;
    role_id: number | null;
    role_title: string | null;
    at: string | null;
}

interface Counts {
    open_roles: number;
    total_roles: number;
    timeline_items: number;
}

interface Props {
    company: CompanyDetail;
    roles: PaginatedRoles;
    roles_filters: RolesFilters;
    role_counts: RoleCounts;
    new_this_week: number;
    timeline: TimelineItem[];
    tags: TagSummary[];
    counts: Counts;
    graveyard: GraveyardInfo | null;
    graveyard_preset_tags: string[];
    hiring_trend: { date: string; open_role_count: number; listings_count: number }[];
    quality: QualityInfo | null;
}

interface QualityFactor {
    weight: number;
    contribution: number;
    value: Record<string, unknown>;
}

interface QualityInfo {
    score: number;
    breakdown: Record<string, QualityFactor>;
    snapshot_date: string | null;
}

const TIER_VARIANT: Record<string, 'default' | 'secondary' | 'outline'> = {
    A: 'default',
    B: 'secondary',
    C: 'outline',
    graveyard: 'outline',
};

const EVENT_VARIANT: Record<string, 'success' | 'destructive' | 'warning' | 'secondary'> = {
    new_role: 'success',
    role_closed: 'destructive',
    role_reopened: 'warning',
    role_updated: 'secondary',
    company_added: 'secondary',
    note_added: 'secondary',
    note: 'secondary',
};

function humanize(value: string): string {
    return value.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

function initialTab(): 'roles' | 'activity' {
    if (typeof window === 'undefined') {
        return 'roles';
    }
    return new URLSearchParams(window.location.search).get('tab') === 'activity' ? 'activity' : 'roles';
}

export default function ShowCompanyPage({
    company,
    roles,
    roles_filters,
    role_counts,
    new_this_week,
    timeline,
    tags,
    counts,
    graveyard,
    graveyard_preset_tags,
    hiring_trend,
    quality,
}: Props) {
    const [tab, setTab] = useState<'roles' | 'activity'>(initialTab);
    const careersUrl = safeHttpUrl(company.careers_url);
    const websiteUrl = safeHttpUrl(company.website);

    return (
        <AuthenticatedLayout>
            <Head title={company.name} />
            <div className="flex flex-col gap-6">
                <header className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                    <div className="flex min-w-0 flex-col gap-2">
                        <Link href="/companies" className="w-fit text-sm text-muted-foreground hover:text-foreground hover:underline">
                            ← Companies
                        </Link>
                        <h1 className="text-2xl font-semibold tracking-tight text-balance">{company.name}</h1>
                        <div className="flex flex-wrap items-center gap-x-3 gap-y-1.5 text-sm text-muted-foreground">
                            <Badge variant={TIER_VARIANT[company.tier] ?? 'outline'}>
                                {company.tier === 'graveyard' ? 'Graveyard' : `Tier ${company.tier}`}
                            </Badge>
                            {websiteUrl ? (
                                <a
                                    href={websiteUrl}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="hover:text-foreground hover:underline"
                                >
                                    {company.domain ? bareDomain(company.domain) : 'Website'} ↗
                                </a>
                            ) : null}
                            {careersUrl ? (
                                <a
                                    href={careersUrl}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="hover:text-foreground hover:underline"
                                >
                                    Careers page ↗
                                </a>
                            ) : null}
                            <span className="tabular-nums">
                                {counts.open_roles} open {counts.open_roles === 1 ? 'role' : 'roles'}
                                {new_this_week > 0 ? (
                                    <>
                                        {' · '}
                                        <span className="font-medium text-primary">{new_this_week} new this week</span>
                                    </>
                                ) : null}
                            </span>
                        </div>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <Button asChild variant="outline">
                            <Link href={`/companies/${company.id}/edit`}>Edit</Link>
                        </Button>
                        <GraveyardControls companyId={company.id} status={company.status} presetTags={graveyard_preset_tags} />
                    </div>
                </header>

                {graveyard ? <GraveyardBanner info={graveyard} /> : null}
                {!graveyard && isUnscrapeable(company) ? <UnscrapeableBanner company={company} /> : null}

                <div className="grid gap-x-10 gap-y-8 lg:grid-cols-[minmax(0,1fr)_20rem]">
                    <main className="min-w-0">
                        <Tabs value={tab} onValueChange={(v) => setTab(v as typeof tab)}>
                            <TabsList>
                                <TabsTrigger value="roles">Roles</TabsTrigger>
                                <TabsTrigger value="activity">Activity ({counts.timeline_items})</TabsTrigger>
                            </TabsList>
                            <TabsContent value="roles">
                                <RolesPanel companyId={company.id} roles={roles} filters={roles_filters} counts={role_counts} />
                            </TabsContent>
                            <TabsContent value="activity">
                                <Timeline items={timeline} />
                            </TabsContent>
                        </Tabs>
                    </main>

                    <aside
                        className="flex min-w-0 flex-col divide-y divide-border *:py-6 *:first:pt-0 lg:*:first:pt-1"
                        aria-label="Company details"
                    >
                        <section className="flex flex-col gap-3">
                            <SectionLabel>Hiring, last 90 days</SectionLabel>
                            <HiringTrendChart data={hiring_trend} height={140} compact />
                        </section>
                        {quality ? <QualityPanel quality={quality} /> : null}
                        <FundingPanel company={company} />
                        <section className="flex flex-col gap-3">
                            <SectionLabel>Tags</SectionLabel>
                            <TagInput companyId={company.id} tags={tags} />
                        </section>
                        <TrackingDetails company={company} counts={counts} />
                    </aside>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

/** Returns the URL when it is http(s), so scraped or typed values never reach an href otherwise. */
function safeHttpUrl(value: string | null): string | null {
    if (!value) {
        return null;
    }
    try {
        const parsed = new URL(value);
        return parsed.protocol === 'http:' || parsed.protocol === 'https:' ? parsed.href : null;
    } catch {
        return null;
    }
}

function bareDomain(domain: string): string {
    return domain.replace(/^[a-z][a-z0-9+.-]*:\/\//i, '').replace(/\/+$/, '');
}

function FundingPanel({ company }: { company: CompanyDetail }) {
    const hasFunding = company.funding_stage || company.funding_last_raised_at || company.funding_last_amount_usd;
    const github = company.github_org ? `https://github.com/${encodeURIComponent(company.github_org)}` : null;
    const blog = safeHttpUrl(company.eng_blog_rss_url);

    return (
        <section className="flex flex-col gap-3">
            <SectionLabel>Funding and presence</SectionLabel>
            <dl className="flex flex-col gap-2 text-base sm:text-sm">
                <SideFact label="Stage" value={company.funding_stage ?? (hasFunding ? null : 'Unknown')} />
                {company.funding_last_raised_at ? (
                    <SideFact label="Last raised" value={<RelativeDate iso={company.funding_last_raised_at} />} />
                ) : null}
                {company.funding_last_amount_usd ? (
                    <SideFact
                        label="Amount"
                        value={
                            <span className="tabular-nums">
                                ${(company.funding_last_amount_usd / 1_000_000).toLocaleString(undefined, { maximumFractionDigits: 1 })}M
                            </span>
                        }
                    />
                ) : null}
                {github ? (
                    <SideFact
                        label="GitHub"
                        value={
                            <a href={github} target="_blank" rel="noopener noreferrer" className="text-primary hover:underline">
                                {company.github_org}
                            </a>
                        }
                    />
                ) : null}
                {blog ? (
                    <SideFact
                        label="Eng blog"
                        value={
                            <a href={blog} target="_blank" rel="noopener noreferrer" className="text-primary hover:underline">
                                Feed ↗
                            </a>
                        }
                    />
                ) : null}
            </dl>
        </section>
    );
}

function SideFact({ label, value }: { label: string; value: ReactNode }) {
    return (
        <div className="flex items-baseline justify-between gap-4">
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="min-w-0 text-right break-words">{value ?? '-'}</dd>
        </div>
    );
}

function TrackingDetails({ company, counts }: { company: CompanyDetail; counts: Counts }) {
    return (
        <section>
            <details className="group">
                <summary className="flex cursor-pointer list-none items-center justify-between gap-2 rounded-md text-sm font-medium text-muted-foreground hover:text-foreground focus-visible:outline-2 focus-visible:outline-ring">
                    Tracking details
                    <ChevronDown aria-hidden="true" className="size-4 transition-transform group-open:rotate-180" />
                </summary>
                <dl className="mt-4 flex flex-col gap-2 text-base sm:text-sm">
                    <SideFact label="ATS" value={company.ats_type ? humanize(company.ats_type) : 'None'} />
                    <SideFact label="Slug" value={company.ats_slug ? <span className="font-mono">{company.ats_slug}</span> : null} />
                    <SideFact label="ATS status" value={humanize(company.ats_status)} />
                    <SideFact label="Status" value={humanize(company.status)} />
                    <SideFact
                        label="Last checked"
                        value={company.last_checked_at ? <RelativeDate iso={company.last_checked_at} /> : 'Never'}
                    />
                    <SideFact label="Roles tracked" value={<span className="tabular-nums">{counts.total_roles}</span>} />
                    <SideFact label="Added" value={company.created_at ? formatDate(company.created_at) : null} />
                    <SideFact
                        label="Last-Modified"
                        value={company.last_modified ? <span className="font-mono text-xs">{company.last_modified}</span> : null}
                    />
                </dl>
            </details>
        </section>
    );
}

const ROLE_STATES: ReadonlyArray<{ value: RoleState; label: string; count: (c: RoleCounts) => number | null }> = [
    { value: 'open', label: 'Open', count: (c) => c.open },
    { value: 'dismissed', label: 'Dismissed', count: (c) => c.dismissed },
    { value: 'closed', label: 'Closed', count: (c) => c.closed },
    { value: 'all', label: 'All', count: () => null },
];

function RolesPanel({
    companyId,
    roles,
    filters,
    counts,
}: {
    companyId: number;
    roles: PaginatedRoles;
    filters: RolesFilters;
    counts: RoleCounts;
}) {
    const [expandedIds, setExpandedIds] = useState<number[]>([]);

    function applyRoleFilters(patch: Partial<RolesFilters>) {
        const next = { ...filters, ...patch };
        const params: Record<string, string> = {};
        if (next.q) params.roles_q = next.q;
        if (next.state !== 'open') params.roles_state = next.state;
        setExpandedIds([]);
        router.get(`/companies/${companyId}`, params, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['roles', 'roles_filters', 'role_counts'],
        });
    }

    function reloadRoles() {
        router.reload({ only: ['roles', 'role_counts', 'counts', 'new_this_week', 'flash'] });
    }

    function dismiss(role: RoleRow) {
        router.post(
            '/bulk-actions',
            { entity: 'role', action: 'dismiss', ids: [role.id] },
            { preserveScroll: true, preserveState: true, onSuccess: reloadRoles },
        );
    }

    function restore(role: RoleRow) {
        router.post(`/roles/${role.id}/restore`, {}, { preserveScroll: true, preserveState: true, onSuccess: reloadRoles });
    }

    function addToPipeline(role: RoleRow) {
        router.post(`/pipeline/roles/${role.id}/add`, {}, { preserveScroll: true, preserveState: true, onSuccess: reloadRoles });
    }

    function onSearchSubmit(e: FormEvent<HTMLFormElement>) {
        e.preventDefault();
        const form = new FormData(e.currentTarget);
        applyRoleFilters({ q: ((form.get('roles_q') as string) ?? '').trim() || null });
    }

    return (
        <div className="flex flex-col gap-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex flex-wrap items-center gap-1" role="group" aria-label="Role state">
                    {ROLE_STATES.map((state) => {
                        const active = filters.state === state.value;
                        const count = state.count(counts);
                        return (
                            <button
                                key={state.value}
                                type="button"
                                aria-pressed={active}
                                onClick={() => applyRoleFilters({ state: state.value })}
                                className={cn(
                                    'min-h-10 rounded-md px-3 text-base sm:min-h-8 sm:text-sm',
                                    active
                                        ? 'bg-muted font-medium text-foreground'
                                        : 'text-muted-foreground hover:bg-muted/60 hover:text-foreground',
                                )}
                            >
                                {state.label}
                                {count !== null ? <span className="ml-1.5 text-muted-foreground tabular-nums">{count}</span> : null}
                            </button>
                        );
                    })}
                </div>
                <form onSubmit={onSearchSubmit} className="flex w-full gap-2 sm:w-auto" role="search">
                    <Input
                        key={filters.q ?? ''}
                        type="search"
                        name="roles_q"
                        defaultValue={filters.q ?? ''}
                        placeholder="Search role titles"
                        aria-label="Search role titles"
                        className="w-full min-w-0 sm:w-56"
                    />
                    <Button type="submit" variant="outline" className="shrink-0">
                        Search
                    </Button>
                </form>
            </div>

            {roles.data.length === 0 ? (
                <EmptyState className="py-12">
                    {filters.q !== null || filters.state !== 'open'
                        ? 'No roles match this search or filter.'
                        : 'No open roles right now. Closed roles stay under Closed.'}
                </EmptyState>
            ) : (
                <>
                    <ul role="list" className="divide-y divide-border rounded-xl border border-border">
                        {roles.data.map((role) => (
                            <RoleLine
                                key={role.id}
                                role={role}
                                expanded={expandedIds.includes(role.id)}
                                focused={false}
                                dismissedView={role.dismissed_at !== null}
                                closed={!role.is_open}
                                onToggle={() =>
                                    setExpandedIds((prev) =>
                                        prev.includes(role.id) ? prev.filter((id) => id !== role.id) : [...prev, role.id],
                                    )
                                }
                                onDismiss={() => dismiss(role)}
                                onRestore={() => restore(role)}
                                onAddToPipeline={() => addToPipeline(role)}
                            />
                        ))}
                    </ul>
                    <RolesPaginator roles={roles} />
                </>
            )}
        </div>
    );
}

function RolesPaginator({ roles }: { roles: PaginatedRoles }) {
    if (roles.last_page <= 1) {
        return null;
    }

    return (
        <div className="flex items-center justify-between gap-2 text-sm text-muted-foreground">
            <span>
                Page <span className="tabular-nums">{roles.current_page}</span> of <span className="tabular-nums">{roles.last_page}</span>
            </span>
            <div className="flex gap-2">
                {roles.prev_page_url ? (
                    <Button asChild variant="outline" size="sm">
                        <Link href={roles.prev_page_url} preserveScroll preserveState only={['roles']}>
                            Previous
                        </Link>
                    </Button>
                ) : null}
                {roles.next_page_url ? (
                    <Button asChild variant="outline" size="sm">
                        <Link href={roles.next_page_url} preserveScroll preserveState only={['roles']}>
                            Next
                        </Link>
                    </Button>
                ) : null}
            </div>
        </div>
    );
}

const TIMELINE_GROUP_LABEL: Record<string, string> = {
    new_role: 'roles added',
    role_closed: 'roles closed',
    role_reopened: 'roles reopened',
    role_updated: 'roles updated',
    company_added: 'company-added events',
    note_added: 'notes',
    note: 'notes',
};

function timelineDayKey(iso: string | null): string {
    if (!iso) return 'unknown';
    const d = new Date(iso);
    return `${d.getFullYear()}-${d.getMonth()}-${d.getDate()}`;
}

/** Informative row text: the item's own title plus the role title, minus anything that would just repeat the badge or duplicate the title. */
function timelineText(item: TimelineItem): string | null {
    const isRedundant = item.kind === 'note' || item.title.trim().toLowerCase() === humanize(item.type).toLowerCase();
    const base = isRedundant ? null : item.title;
    const roleSuffix = item.role_title && !(base ?? '').includes(item.role_title) ? item.role_title : null;
    const parts = [base, roleSuffix].filter((p): p is string => Boolean(p));
    return parts.length > 0 ? parts.join(' · ') : null;
}

interface TimelineGroup {
    key: string;
    type: string;
    at: string | null;
    items: TimelineItem[];
}

function groupTimeline(items: TimelineItem[]): TimelineGroup[] {
    const groups: TimelineGroup[] = [];
    for (const item of items) {
        const last = groups[groups.length - 1];
        if (last && last.type === item.type && timelineDayKey(last.at) === timelineDayKey(item.at)) {
            last.items.push(item);
        } else {
            groups.push({ key: item.id, type: item.type, at: item.at, items: [item] });
        }
    }
    return groups;
}

function Timeline({ items }: { items: TimelineItem[] }) {
    if (items.length === 0) {
        return <EmptyState>Nothing has happened here yet.</EmptyState>;
    }

    const groups = groupTimeline(items);

    return (
        <ol role="list" className="divide-y divide-border">
            {groups.map((group) => {
                const [first] = group.items;
                return group.items.length === 1 && first ? (
                    <TimelineRow key={group.key} item={first} />
                ) : (
                    <TimelineGroupRow key={group.key} group={group} />
                );
            })}
        </ol>
    );
}

function TimelineRow({ item }: { item: TimelineItem }) {
    const text = timelineText(item);

    return (
        <li className="flex flex-col gap-1 py-4 sm:flex-row sm:items-baseline sm:justify-between sm:gap-4">
            <div className="flex min-w-0 flex-1 flex-col gap-1">
                <div className="flex flex-wrap items-center gap-2 text-base sm:text-sm">
                    <Badge variant={EVENT_VARIANT[item.type] ?? 'secondary'}>{humanize(item.type)}</Badge>
                    {text ? <div className="min-w-0">{text}</div> : null}
                </div>
                {item.body ? <p className="text-sm text-pretty text-muted-foreground">{item.body}</p> : null}
            </div>
            <span className="text-xs tabular-nums text-muted-foreground" title={item.at ? formatDateTime(item.at) : undefined}>
                {item.at ? formatDateTime(item.at) : ''}
            </span>
        </li>
    );
}

function TimelineGroupRow({ group }: { group: TimelineGroup }) {
    const label = TIMELINE_GROUP_LABEL[group.type] ?? `${humanize(group.type).toLowerCase()} events`;

    return (
        <li className="py-4">
            <details>
                <summary className="flex cursor-pointer list-none flex-col gap-1 sm:flex-row sm:items-baseline sm:justify-between">
                    <div className="flex flex-wrap items-center gap-2 text-base sm:text-sm">
                        <Badge variant={EVENT_VARIANT[group.type] ?? 'secondary'}>{humanize(group.type)}</Badge>
                        <span className="tabular-nums">
                            {group.items.length} {label}
                        </span>
                    </div>
                    <span className="text-xs tabular-nums text-muted-foreground">{formatDate(group.at)}</span>
                </summary>
                <ul role="list" className="mt-2 flex flex-col gap-1 border-l border-border pl-4">
                    {group.items.map((item) => (
                        <li key={item.id} className="flex items-center justify-between gap-2 text-sm text-muted-foreground">
                            <span className="min-w-0">{timelineText(item) ?? humanize(item.type)}</span>
                            <span className="shrink-0 text-xs tabular-nums">{item.at ? formatDateTime(item.at) : ''}</span>
                        </li>
                    ))}
                </ul>
            </details>
        </li>
    );
}

function isUnscrapeable(company: CompanyDetail): boolean {
    return !company.ats_type || !company.ats_slug || company.ats_status === 'redetect_failed';
}

function UnscrapeableBanner({ company }: { company: CompanyDetail }) {
    const reason =
        company.ats_status === 'redetect_failed'
            ? 'ATS redetection gave up after repeated failures. Update the careers URL, then set the ATS type and slug and reset the ATS status to retry.'
            : 'No ATS type or slug is set, so this company is never picked up by a scrape run. Set the ATS type and slug to start tracking postings automatically.';

    return (
        <div className="flex flex-col gap-2 rounded-lg border border-amber-600/20 bg-amber-50 p-4 dark:border-amber-400/20 dark:bg-amber-400/10">
            <div>
                <Badge variant="warning">Not being scraped</Badge>
            </div>
            <p className="max-w-prose text-base text-pretty text-muted-foreground sm:text-sm">{reason}</p>
        </div>
    );
}

function GraveyardBanner({ info }: { info: GraveyardInfo }) {
    return (
        <div className="flex flex-col gap-2 rounded-lg border border-rose-600/20 bg-rose-50 p-4 dark:border-rose-400/20 dark:bg-rose-400/10">
            <div className="flex flex-wrap items-center gap-2">
                <Badge variant="destructive">Graveyard</Badge>
                <div className="text-sm text-muted-foreground">
                    Demoted <span className="tabular-nums">{info.demoted_at ? new Date(info.demoted_at).toLocaleString() : ''}</span>
                </div>
                {info.tags.map((tag) => (
                    <Badge key={tag} variant="outline">
                        {humanize(tag)}
                    </Badge>
                ))}
            </div>
            {info.reason ? <p className="max-w-prose text-base text-pretty text-muted-foreground sm:text-sm">{info.reason}</p> : null}
        </div>
    );
}

function GraveyardControls({ companyId, status, presetTags }: { companyId: number; status: string; presetTags: string[] }) {
    const [open, setOpen] = useState(false);
    const demote = useForm<{ reason: string; tags: string[] }>({ reason: '', tags: [] });
    const restore = useForm({});

    if (status === 'graveyard') {
        return (
            <Button
                type="button"
                variant="outline"
                disabled={restore.processing}
                onClick={() => restore.post(`/companies/${companyId}/restore`, { preserveScroll: true })}
            >
                Restore to C-tier
            </Button>
        );
    }

    return (
        <div className="relative">
            <Button type="button" variant="outline" onClick={() => setOpen((v) => !v)}>
                Move to graveyard
            </Button>
            {open ? (
                <div className="absolute right-0 z-10 mt-2 flex w-80 max-w-[calc(100vw-2rem)] flex-col gap-3 rounded-lg border bg-popover p-4 shadow-md">
                    <label htmlFor="graveyard-reason" className="text-sm font-medium text-muted-foreground">
                        Reason
                    </label>
                    <textarea
                        id="graveyard-reason"
                        name="reason"
                        className="min-h-20 w-full rounded-md border border-input bg-background p-2 text-base focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring sm:text-sm"
                        placeholder="Why are you graveyarding this company?"
                        value={demote.data.reason}
                        onChange={(e) => demote.setData('reason', e.target.value)}
                    />
                    <div className="flex flex-wrap gap-1.5">
                        {presetTags.map((tag) => {
                            const active = demote.data.tags.includes(tag);
                            return (
                                <button
                                    key={tag}
                                    type="button"
                                    aria-pressed={active}
                                    className={cn(
                                        'rounded-md border px-2.5 py-1 text-sm',
                                        active
                                            ? 'border-border bg-muted font-medium text-foreground'
                                            : 'border-border text-muted-foreground hover:text-foreground',
                                    )}
                                    onClick={() =>
                                        demote.setData(
                                            'tags',
                                            active ? demote.data.tags.filter((t) => t !== tag) : [...demote.data.tags, tag],
                                        )
                                    }
                                >
                                    {humanize(tag)}
                                </button>
                            );
                        })}
                    </div>
                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="ghost" size="sm" onClick={() => setOpen(false)}>
                            Cancel
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            disabled={demote.processing}
                            onClick={() =>
                                demote.post(`/companies/${companyId}/graveyard`, {
                                    preserveScroll: true,
                                    onSuccess: () => setOpen(false),
                                })
                            }
                        >
                            Demote
                        </Button>
                    </div>
                </div>
            ) : null}
        </div>
    );
}

const FACTOR_LABEL: Record<string, string> = {
    funding_recency: 'Funding recency',
    github_activity: 'GitHub activity',
    blog_cadence: 'Engineering blog cadence',
    hiring_volume_stability: 'Hiring volume stability',
    comp_transparency: 'Comp transparency',
    layoff_penalty: 'Recent layoffs',
    ghost_density_penalty: 'Ghost-role density',
    no_eng_presence_penalty: 'No eng presence',
};

function QualityPanel({ quality }: { quality: QualityInfo }) {
    const score = quality.score;
    const tone =
        score >= 80
            ? 'text-emerald-600 dark:text-emerald-400'
            : score >= 60
              ? 'text-amber-600 dark:text-amber-400'
              : 'text-rose-600 dark:text-rose-400';

    return (
        <section className="flex flex-col gap-3">
            <div className="flex items-baseline justify-between gap-2">
                <SectionLabel>Quality score</SectionLabel>
                <span className="text-xs text-muted-foreground">
                    as of <span className="tabular-nums">{quality.snapshot_date ?? '-'}</span>
                </span>
            </div>
            <div className="flex items-baseline gap-1.5">
                <span className={cn('text-3xl font-semibold tabular-nums', tone)}>{score}</span>
                <span className="text-sm text-muted-foreground tabular-nums">/ 100</span>
            </div>
            <details className="group">
                <summary className="flex cursor-pointer list-none items-center gap-1 rounded-md text-sm text-muted-foreground hover:text-foreground focus-visible:outline-2 focus-visible:outline-ring">
                    How it adds up
                    <ChevronDown aria-hidden="true" className="size-4 transition-transform group-open:rotate-180" />
                </summary>
                <dl className="mt-3 flex flex-col gap-1.5 text-sm">
                    {Object.entries(quality.breakdown).map(([key, factor]) => (
                        <div key={key} className="flex items-baseline justify-between gap-3">
                            <dt className="min-w-0 text-muted-foreground">{FACTOR_LABEL[key] ?? key}</dt>
                            <dd
                                className={cn(
                                    'shrink-0 font-medium tabular-nums',
                                    factor.contribution > 0
                                        ? 'text-emerald-600 dark:text-emerald-400'
                                        : factor.contribution < 0
                                          ? 'text-rose-600 dark:text-rose-400'
                                          : 'text-muted-foreground',
                                )}
                                title={`Weight ${factor.weight}`}
                            >
                                {factor.contribution > 0 ? '+' : ''}
                                {factor.contribution}
                            </dd>
                        </div>
                    ))}
                </dl>
                <p className="mt-3 text-sm text-pretty text-muted-foreground">
                    Starts from a baseline. Each factor adds up to its weight, and each penalty subtracts up to its weight.
                </p>
            </details>
        </section>
    );
}
