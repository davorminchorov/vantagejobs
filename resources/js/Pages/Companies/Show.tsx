import { Head, Link, router, useForm } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';
import { useState } from 'react';

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import HiringTrendChart from '@/components/charts/HiringTrendChart';
import TagInput, { type TagSummary } from '@/components/companies/TagInput';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { DataTableScroll } from '@/components/ui/data-table-scroll';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { SectionLabel } from '@/components/ui/section-label';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { formatDate, formatDateTime, RelativeDate } from '@/lib/dates';

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
}

interface GraveyardInfo {
    demoted_at: string | null;
    reason: string | null;
    tags: string[];
}

interface RoleRow {
    id: number;
    title: string;
    source: string;
    url: string | null;
    location: string | null;
    workplace_type: string | null;
    department: string | null;
    team: string | null;
    comp_min: number | null;
    comp_max: number | null;
    comp_currency: string | null;
    comp_interval: string | null;
    is_open: boolean;
    posted_at: string | null;
    last_seen_at: string | null;
}

interface PaginatedRoles {
    data: RoleRow[];
    current_page: number;
    last_page: number;
    total: number;
    links: { url: string | null; label: string; active: boolean }[];
    prev_page_url: string | null;
    next_page_url: string | null;
}

interface RolesFilters {
    q: string | null;
    state: 'open' | 'closed' | null;
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

function formatComp(role: RoleRow): string | null {
    if (role.comp_min === null && role.comp_max === null) return null;
    const currency = role.comp_currency ?? '';
    const min = role.comp_min !== null ? role.comp_min.toLocaleString() : '?';
    const max = role.comp_max !== null ? role.comp_max.toLocaleString() : '?';
    const interval = role.comp_interval ? ` / ${role.comp_interval}` : '';
    return `${currency} ${min}-${max}${interval}`.trim();
}

function initialTab(): 'overview' | 'roles' | 'timeline' {
    if (typeof window === 'undefined') {
        return 'overview';
    }
    const params = new URLSearchParams(window.location.search);
    if (params.has('roles_page') || params.has('roles_q') || params.has('roles_state')) {
        return 'roles';
    }
    return 'overview';
}

export default function ShowCompanyPage({
    company,
    roles,
    roles_filters,
    timeline,
    tags,
    counts,
    graveyard,
    graveyard_preset_tags,
    hiring_trend,
    quality,
}: Props) {
    const [tab, setTab] = useState<'overview' | 'roles' | 'timeline'>(initialTab);

    return (
        <AuthenticatedLayout>
            <Head title={company.name} />
            <div className="space-y-6">
                <header className="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                    <div className="space-y-1">
                        <div className="flex items-center gap-2">
                            <Link href="/companies" className="text-sm text-teal-700 hover:text-teal-800">
                                ← Companies
                            </Link>
                        </div>
                        <h1 className="text-2xl font-semibold tracking-tight">{company.name}</h1>
                        <div className="flex flex-wrap items-center gap-2">
                            <Badge variant={TIER_VARIANT[company.tier] ?? 'outline'}>
                                {company.tier === 'graveyard' ? 'Graveyard' : `Tier ${company.tier}`}
                            </Badge>
                            <span className="text-sm text-muted-foreground">{humanize(company.status)}</span>
                            {company.ats_type ? (
                                <span className="text-xs text-muted-foreground">
                                    {humanize(company.ats_type)}
                                    {company.ats_slug ? (
                                        <span className="font-mono"> · {company.ats_slug}</span>
                                    ) : ''}
                                </span>
                            ) : null}
                        </div>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <Button asChild variant="outline">
                            <Link href={`/companies/${company.id}/edit`}>Edit</Link>
                        </Button>
                        <GraveyardControls
                            companyId={company.id}
                            status={company.status}
                            presetTags={graveyard_preset_tags}
                        />
                    </div>
                </header>

                {graveyard ? <GraveyardBanner info={graveyard} /> : null}
                {! graveyard && isUnscrapeable(company) ? <UnscrapeableBanner company={company} /> : null}

                <Tabs value={tab} onValueChange={(v) => setTab(v as typeof tab)}>
                    <TabsList>
                        <TabsTrigger value="overview">Overview</TabsTrigger>
                        <TabsTrigger value="roles">Roles ({counts.total_roles})</TabsTrigger>
                        <TabsTrigger value="timeline">Timeline ({counts.timeline_items})</TabsTrigger>
                    </TabsList>

                    <TabsContent value="overview">
                        {quality ? <QualityCard quality={quality} /> : null}
                        <Card className="mb-4 rounded-2xl">
                            <CardHeader>
                                <CardTitle>
                                    <SectionLabel>Hiring volume (90 days)</SectionLabel>
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <HiringTrendChart data={hiring_trend} />
                            </CardContent>
                        </Card>
                        <Overview company={company} counts={counts} tags={tags} />
                    </TabsContent>

                    <TabsContent value="roles">
                        <RolesTable companyId={company.id} roles={roles} filters={roles_filters} />
                    </TabsContent>

                    <TabsContent value="timeline">
                        <Timeline items={timeline} />
                    </TabsContent>
                </Tabs>
            </div>
        </AuthenticatedLayout>
    );
}

function Overview({ company, counts, tags }: { company: CompanyDetail; counts: Counts; tags: TagSummary[] }) {
    return (
        <div className="grid gap-4 lg:grid-cols-2">
            <Card className="rounded-2xl lg:col-span-2">
                <CardHeader>
                    <CardTitle>
                        <SectionLabel>Tags</SectionLabel>
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <TagInput companyId={company.id} tags={tags} />
                </CardContent>
            </Card>

            <Card className="rounded-2xl">
                <CardHeader>
                    <CardTitle>
                        <SectionLabel>Identity</SectionLabel>
                    </CardTitle>
                </CardHeader>
                <CardContent className="space-y-2 text-sm">
                    <DefinitionRow label="Name" value={company.name} />
                    <DefinitionRow label="Website" value={company.website} link />
                    <DefinitionRow label="Careers" value={company.careers_url} link />
                    <DefinitionRow label="Domain" value={company.domain} />
                </CardContent>
            </Card>

            <Card className="rounded-2xl">
                <CardHeader>
                    <CardTitle>
                        <SectionLabel>ATS</SectionLabel>
                    </CardTitle>
                </CardHeader>
                <CardContent className="space-y-2 text-sm">
                    <DefinitionRow label="Type" value={company.ats_type ? humanize(company.ats_type) : null} />
                    <DefinitionRow label="Slug" value={company.ats_slug} />
                    <DefinitionRow label="Last-Modified" value={company.last_modified} mono />
                </CardContent>
            </Card>

            <Card className="rounded-2xl">
                <CardHeader>
                    <CardTitle>
                        <SectionLabel>Operational</SectionLabel>
                    </CardTitle>
                </CardHeader>
                <CardContent className="space-y-2 text-sm">
                    <DefinitionRow
                        label="Last checked"
                        value={company.last_checked_at ? <RelativeDate iso={company.last_checked_at} /> : 'Never'}
                    />
                    <DefinitionRow
                        label="Added"
                        value={company.created_at ? formatDate(company.created_at) : null}
                        mono
                    />
                </CardContent>
            </Card>

            <Card className="rounded-2xl">
                <CardHeader>
                    <CardTitle>
                        <SectionLabel>Counts</SectionLabel>
                    </CardTitle>
                </CardHeader>
                <CardContent className="space-y-2 text-sm">
                    <DefinitionRow label="Open roles" value={String(counts.open_roles)} mono />
                    <DefinitionRow label="Total roles tracked" value={String(counts.total_roles)} mono />
                </CardContent>
            </Card>
        </div>
    );
}

function DefinitionRow({
    label,
    value,
    link,
    mono,
}: {
    label: string;
    value: ReactNode;
    link?: boolean;
    mono?: boolean;
}) {
    const isEmpty = value === null || value === undefined || value === '';

    return (
        <div className="flex flex-col gap-0.5 sm:grid sm:grid-cols-[10rem_1fr] sm:items-baseline">
            <span className="font-mono text-xs uppercase tracking-wide text-muted-foreground">{label}</span>
            {isEmpty ? (
                <span className="text-sm text-muted-foreground">—</span>
            ) : link && typeof value === 'string' ? (
                <a
                    href={value}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="break-all text-sm text-teal-700 hover:text-teal-800 hover:underline"
                >
                    {value}
                </a>
            ) : (
                <span className={`break-all text-sm${mono ? ' font-mono tabular-nums' : ''}`}>{value}</span>
            )}
        </div>
    );
}

function RolesTable({
    companyId,
    roles,
    filters,
}: {
    companyId: number;
    roles: PaginatedRoles;
    filters: RolesFilters;
}) {
    const hasComp = roles.data.some((role) => formatComp(role) !== null);
    const hasActiveFilter = filters.q !== null || filters.state !== null;

    function applyRoleFilters(patch: Partial<RolesFilters>) {
        const next = { ...filters, ...patch };
        const params: Record<string, string> = {};
        if (next.q) params.roles_q = next.q;
        if (next.state) params.roles_state = next.state;
        router.get(`/companies/${companyId}`, params, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['roles', 'roles_filters', 'counts'],
        });
    }

    return (
        <div className="space-y-3">
            <RolesFilterBar filters={filters} onChange={applyRoleFilters} />

            {roles.data.length === 0 ? (
                <Card className="rounded-2xl">
                    <CardContent className="px-6 py-12 text-center text-sm text-muted-foreground">
                        {hasActiveFilter ? (
                            'No roles match the current search or filter.'
                        ) : (
                            <>
                                No roles tracked yet. Run{' '}
                                <code className="rounded bg-muted px-1 py-0.5 font-mono">scrape:company</code> to fetch.
                            </>
                        )}
                    </CardContent>
                </Card>
            ) : (
                <>
                    <p className="text-sm text-muted-foreground">
                        Showing <span className="font-mono tabular-nums">{roles.data.length}</span> of{' '}
                        <span className="font-mono tabular-nums">{roles.total}</span> roles
                    </p>
                    <Card className="rounded-2xl">
                        <CardContent className="p-0">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Title</TableHead>
                                        <TableHead>Workplace</TableHead>
                                        {hasComp ? <TableHead>Comp</TableHead> : null}
                                        <TableHead>Source</TableHead>
                                        <TableHead>Last seen</TableHead>
                                        <TableHead className="text-right">State</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {roles.data.map((role) => {
                                        const comp = formatComp(role);
                                        const isUnspecified = role.workplace_type === null || role.workplace_type === 'unspecified';
                                        return (
                                            <TableRow key={role.id}>
                                                <TableCell>
                                                    <div className="flex flex-col">
                                                        {role.url ? (
                                                            <a
                                                                href={role.url}
                                                                target="_blank"
                                                                rel="noopener noreferrer"
                                                                className="font-medium text-teal-700 hover:text-teal-800 hover:underline"
                                                            >
                                                                {role.title}
                                                            </a>
                                                        ) : (
                                                            <span className="font-medium">{role.title}</span>
                                                        )}
                                                        {role.team ?? role.department ? (
                                                            <span className="text-xs text-muted-foreground">
                                                                {[role.department, role.team].filter(Boolean).join(' / ')}
                                                            </span>
                                                        ) : null}
                                                    </div>
                                                </TableCell>
                                                <TableCell>
                                                    <div className="flex flex-col">
                                                        <span className={isUnspecified ? 'text-sm text-muted-foreground' : 'text-sm'}>
                                                            {isUnspecified ? '—' : humanize(role.workplace_type as string)}
                                                        </span>
                                                        {role.location ? (
                                                            <span className="text-xs text-muted-foreground">{role.location}</span>
                                                        ) : null}
                                                    </div>
                                                </TableCell>
                                                {hasComp ? (
                                                    <TableCell>
                                                        {comp ? (
                                                            <span className="font-mono text-sm tabular-nums">{comp}</span>
                                                        ) : (
                                                            <span className="text-sm text-muted-foreground">—</span>
                                                        )}
                                                    </TableCell>
                                                ) : null}
                                                <TableCell>
                                                    <span className="font-mono text-xs text-muted-foreground">{role.source}</span>
                                                </TableCell>
                                                <TableCell>
                                                    <span className="font-mono text-xs tabular-nums text-muted-foreground">
                                                        {role.last_seen_at ? new Date(role.last_seen_at).toLocaleDateString() : '—'}
                                                    </span>
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    {role.is_open ? (
                                                        <Badge variant="success">Open</Badge>
                                                    ) : (
                                                        <Badge variant="outline">Closed</Badge>
                                                    )}
                                                </TableCell>
                                            </TableRow>
                                        );
                                    })}
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>
                    <RolesPaginator roles={roles} />
                </>
            )}
        </div>
    );
}

function RolesFilterBar({
    filters,
    onChange,
}: {
    filters: RolesFilters;
    onChange: (patch: Partial<RolesFilters>) => void;
}) {
    function onSearchSubmit(e: FormEvent<HTMLFormElement>) {
        e.preventDefault();
        const form = new FormData(e.currentTarget);
        onChange({ q: ((form.get('roles_q') as string) ?? '').trim() || null });
    }

    return (
        <form onSubmit={onSearchSubmit} className="flex flex-wrap items-center gap-2">
            <Input
                type="search"
                name="roles_q"
                defaultValue={filters.q ?? ''}
                placeholder="Search role titles…"
                aria-label="Search role titles"
                className="w-full sm:w-64"
            />
            <select
                value={filters.state ?? ''}
                onChange={(e) => onChange({ state: e.target.value === '' ? null : (e.target.value as 'open' | 'closed') })}
                aria-label="Filter by role state"
                className="h-9 rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            >
                <option value="">All states</option>
                <option value="open">Open</option>
                <option value="closed">Closed</option>
            </select>
            <Button type="submit" variant="outline" size="sm">
                Search
            </Button>
        </form>
    );
}

function RolesPaginator({ roles }: { roles: PaginatedRoles }) {
    if (roles.last_page <= 1) {
        return null;
    }

    return (
        <div className="flex items-center justify-between text-sm text-muted-foreground">
            <span>
                Page <span className="font-mono tabular-nums">{roles.current_page}</span> of{' '}
                <span className="font-mono tabular-nums">{roles.last_page}</span>
            </span>
            <div className="flex gap-2">
                {roles.prev_page_url ? (
                    <Link
                        href={roles.prev_page_url}
                        preserveScroll
                        preserveState
                        only={['roles']}
                        className="rounded-md border border-border px-3 py-1 hover:bg-muted"
                    >
                        Prev
                    </Link>
                ) : null}
                {roles.next_page_url ? (
                    <Link
                        href={roles.next_page_url}
                        preserveScroll
                        preserveState
                        only={['roles']}
                        className="rounded-md border border-border px-3 py-1 hover:bg-muted"
                    >
                        Next
                    </Link>
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
        <Card className="rounded-2xl">
            <CardContent className="p-0">
                <ol className="divide-y">
                    {groups.map((group) => {
                        const [first] = group.items;
                        return group.items.length === 1 && first ? (
                            <TimelineRow key={group.key} item={first} />
                        ) : (
                            <TimelineGroupRow key={group.key} group={group} />
                        );
                    })}
                </ol>
            </CardContent>
        </Card>
    );
}

function TimelineRow({ item }: { item: TimelineItem }) {
    const text = timelineText(item);

    return (
        <li className="flex flex-col gap-1 px-6 py-4 sm:flex-row sm:items-baseline sm:justify-between">
            <div className="flex-1 space-y-1">
                <div className="flex items-center gap-2">
                    <Badge variant={EVENT_VARIANT[item.type] ?? 'secondary'}>{humanize(item.type)}</Badge>
                    {text ? <span className="text-sm">{text}</span> : null}
                </div>
                {item.body ? <p className="text-xs text-muted-foreground">{item.body}</p> : null}
            </div>
            <span className="font-mono text-xs tabular-nums text-muted-foreground" title={item.at ? formatDateTime(item.at) : undefined}>
                {item.at ? formatDateTime(item.at) : ''}
            </span>
        </li>
    );
}

function TimelineGroupRow({ group }: { group: TimelineGroup }) {
    const label = TIMELINE_GROUP_LABEL[group.type] ?? `${humanize(group.type).toLowerCase()} events`;

    return (
        <li className="px-6 py-4">
            <details>
                <summary className="flex cursor-pointer list-none flex-col gap-1 sm:flex-row sm:items-baseline sm:justify-between">
                    <div className="flex items-center gap-2">
                        <Badge variant={EVENT_VARIANT[group.type] ?? 'secondary'}>{humanize(group.type)}</Badge>
                        <span className="text-sm">
                            {group.items.length} {label}
                        </span>
                    </div>
                    <span className="font-mono text-xs tabular-nums text-muted-foreground">{formatDate(group.at)}</span>
                </summary>
                <ul className="mt-2 space-y-1 border-l border-border pl-4">
                    {group.items.map((item) => (
                        <li key={item.id} className="flex items-center justify-between gap-2 text-xs text-muted-foreground">
                            <span>{timelineText(item) ?? humanize(item.type)}</span>
                            <span className="font-mono tabular-nums">{item.at ? formatDateTime(item.at) : ''}</span>
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
        <Card className="rounded-2xl border-amber-400/40 bg-amber-50/50 dark:bg-amber-900/10">
            <CardContent className="space-y-2 py-4 text-sm">
                <div className="flex flex-wrap items-center gap-2">
                    <Badge variant="warning">Not being scraped</Badge>
                </div>
                <p className="text-muted-foreground">{reason}</p>
            </CardContent>
        </Card>
    );
}

function GraveyardBanner({ info }: { info: GraveyardInfo }) {
    return (
        <Card className="rounded-2xl border-destructive/40 bg-destructive/5">
            <CardContent className="space-y-2 py-4 text-sm">
                <div className="flex flex-wrap items-center gap-2">
                    <Badge variant="destructive">Graveyard</Badge>
                    <span className="text-xs text-muted-foreground">
                        Demoted <span className="font-mono tabular-nums">{info.demoted_at ? new Date(info.demoted_at).toLocaleString() : ''}</span>
                    </span>
                    {info.tags.map((tag) => (
                        <Badge key={tag} variant="outline">
                            {humanize(tag)}
                        </Badge>
                    ))}
                </div>
                {info.reason ? <p className="text-muted-foreground">{info.reason}</p> : null}
            </CardContent>
        </Card>
    );
}

function GraveyardControls({
    companyId,
    status,
    presetTags,
}: {
    companyId: number;
    status: string;
    presetTags: string[];
}) {
    const [open, setOpen] = useState(false);
    const demote = useForm<{ reason: string; tags: string[] }>({ reason: '', tags: [] });
    const restore = useForm({});

    if (status === 'graveyard') {
        return (
            <Button
                variant="outline"
                disabled={restore.processing}
                onClick={() =>
                    restore.post(`/companies/${companyId}/restore`, { preserveScroll: true })
                }
            >
                Restore to C-tier
            </Button>
        );
    }

    return (
        <div className="relative">
            <Button variant="outline" onClick={() => setOpen((v) => !v)}>
                Move to graveyard
            </Button>
            {open ? (
                <div className="absolute right-0 z-10 mt-2 w-80 space-y-3 rounded-md border bg-background p-3 shadow-md">
                    <p className="font-mono text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                        Reason
                    </p>
                    <textarea
                        className="min-h-20 w-full rounded-md border bg-background p-2 text-sm"
                        placeholder="Why are you graveyarding this company?"
                        value={demote.data.reason}
                        onChange={(e) => demote.setData('reason', e.target.value)}
                    />
                    <div className="flex flex-wrap gap-1">
                        {presetTags.map((tag) => {
                            const active = demote.data.tags.includes(tag);
                            return (
                                <button
                                    key={tag}
                                    type="button"
                                    className={`rounded-full border px-2.5 py-1 text-xs ${
                                        active
                                            ? 'border-primary bg-primary text-primary-foreground'
                                            : 'border-border text-muted-foreground hover:text-foreground'
                                    }`}
                                    onClick={() =>
                                        demote.setData(
                                            'tags',
                                            active
                                                ? demote.data.tags.filter((t) => t !== tag)
                                                : [...demote.data.tags, tag],
                                        )
                                    }
                                >
                                    {humanize(tag)}
                                </button>
                            );
                        })}
                    </div>
                    <div className="flex justify-end gap-2">
                        <Button variant="ghost" size="sm" onClick={() => setOpen(false)}>
                            Cancel
                        </Button>
                        <Button
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

function QualityCard({ quality }: { quality: QualityInfo }) {
    const score = quality.score;
    const tone =
        score >= 80 ? 'text-emerald-600' : score >= 60 ? 'text-amber-600' : 'text-red-600';

    return (
        <Card className="mb-4 rounded-2xl">
            <CardHeader className="flex flex-row items-baseline justify-between space-y-0">
                <CardTitle>
                    <SectionLabel>Quality score</SectionLabel>
                </CardTitle>
                <span className="text-xs text-muted-foreground">
                    as of <span className="font-mono tabular-nums">{quality.snapshot_date ?? '—'}</span>
                </span>
            </CardHeader>
            <CardContent className="space-y-3">
                <div className="flex items-baseline gap-2">
                    <span className={`font-mono text-4xl font-semibold tabular-nums ${tone}`}>{score}</span>
                    <span className="font-mono text-sm tabular-nums text-muted-foreground">/ 100</span>
                </div>
                <DataTableScroll>
                    <table className="w-full min-w-[20rem] text-sm">
                        <thead className="font-mono text-xs uppercase tracking-wide text-muted-foreground">
                            <tr>
                                <th className="text-left font-semibold">Factor</th>
                                <th className="text-right font-semibold">Weight</th>
                                <th className="text-right font-semibold">Contribution</th>
                            </tr>
                        </thead>
                        <tbody>
                            {Object.entries(quality.breakdown).map(([key, factor]) => (
                                <tr key={key} className="border-t">
                                    <td className="py-1.5">{FACTOR_LABEL[key] ?? key}</td>
                                    <td className="text-right font-mono tabular-nums text-muted-foreground">
                                        {factor.weight > 0 ? '+' : ''}
                                        {factor.weight}
                                    </td>
                                    <td
                                        className={`text-right font-mono font-semibold tabular-nums ${
                                            factor.contribution > 0
                                                ? 'text-emerald-600'
                                                : factor.contribution < 0
                                                  ? 'text-red-600'
                                                  : 'text-muted-foreground'
                                        }`}
                                    >
                                        {factor.contribution > 0 ? '+' : ''}
                                        {factor.contribution}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </DataTableScroll>
                <p className="text-sm text-muted-foreground">
                    Score starts at the baseline and is adjusted by each factor. Positive
                    factors max at their weight; penalties subtract up to their weight.
                </p>
            </CardContent>
        </Card>
    );
}
