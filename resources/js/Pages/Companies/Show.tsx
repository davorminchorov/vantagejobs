import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';

interface CompanyDetail {
    id: number;
    name: string;
    website: string | null;
    careers_url: string | null;
    domain: string | null;
    ats_type: string | null;
    ats_slug: string | null;
    tier: 'A' | 'B' | 'C' | 'graveyard';
    status: string;
    last_checked_at: string | null;
    etag: string | null;
    last_modified: string | null;
    created_at: string | null;
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
}

interface TimelineItem {
    kind: 'event' | 'note';
    id: string;
    type: string;
    title: string;
    body: string | null;
    role_id: number | null;
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
    timeline: TimelineItem[];
    counts: Counts;
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

export default function ShowCompanyPage({ company, roles, timeline, counts }: Props) {
    const [tab, setTab] = useState<'overview' | 'roles' | 'timeline'>('overview');

    return (
        <AuthenticatedLayout>
            <Head title={company.name} />
            <div className="space-y-6">
                <header className="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                    <div className="space-y-1">
                        <div className="flex items-center gap-2">
                            <Link href="/companies" className="text-xs text-muted-foreground hover:text-foreground">
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
                                    {company.ats_slug ? ` · ${company.ats_slug}` : ''}
                                </span>
                            ) : null}
                        </div>
                    </div>
                    <div className="flex items-center gap-2">
                        <Button asChild variant="outline">
                            <Link href={`/companies/${company.id}/edit`}>Edit</Link>
                        </Button>
                    </div>
                </header>

                <Tabs value={tab} onValueChange={(v) => setTab(v as typeof tab)}>
                    <TabsList>
                        <TabsTrigger value="overview">Overview</TabsTrigger>
                        <TabsTrigger value="roles">Roles ({counts.total_roles})</TabsTrigger>
                        <TabsTrigger value="timeline">Timeline ({counts.timeline_items})</TabsTrigger>
                    </TabsList>

                    <TabsContent value="overview">
                        <Overview company={company} counts={counts} />
                    </TabsContent>

                    <TabsContent value="roles">
                        <RolesTable roles={roles} />
                    </TabsContent>

                    <TabsContent value="timeline">
                        <Timeline items={timeline} />
                    </TabsContent>
                </Tabs>
            </div>
        </AuthenticatedLayout>
    );
}

function Overview({ company, counts }: { company: CompanyDetail; counts: Counts }) {
    return (
        <div className="grid gap-4 lg:grid-cols-2">
            <Card>
                <CardHeader>
                    <CardTitle className="text-sm font-semibold uppercase tracking-wide text-muted-foreground">
                        Identity
                    </CardTitle>
                </CardHeader>
                <CardContent className="space-y-2 text-sm">
                    <DefinitionRow label="Name" value={company.name} />
                    <DefinitionRow label="Website" value={company.website} link />
                    <DefinitionRow label="Careers" value={company.careers_url} link />
                    <DefinitionRow label="Domain" value={company.domain} />
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle className="text-sm font-semibold uppercase tracking-wide text-muted-foreground">
                        ATS
                    </CardTitle>
                </CardHeader>
                <CardContent className="space-y-2 text-sm">
                    <DefinitionRow label="Type" value={company.ats_type ? humanize(company.ats_type) : null} />
                    <DefinitionRow label="Slug" value={company.ats_slug} />
                    <DefinitionRow label="ETag" value={company.etag} />
                    <DefinitionRow label="Last-Modified" value={company.last_modified} />
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle className="text-sm font-semibold uppercase tracking-wide text-muted-foreground">
                        Operational
                    </CardTitle>
                </CardHeader>
                <CardContent className="space-y-2 text-sm">
                    <DefinitionRow
                        label="Last checked"
                        value={company.last_checked_at ? new Date(company.last_checked_at).toLocaleString() : 'Never'}
                    />
                    <DefinitionRow
                        label="Added"
                        value={company.created_at ? new Date(company.created_at).toLocaleDateString() : null}
                    />
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle className="text-sm font-semibold uppercase tracking-wide text-muted-foreground">
                        Counts
                    </CardTitle>
                </CardHeader>
                <CardContent className="space-y-2 text-sm">
                    <DefinitionRow label="Open roles" value={String(counts.open_roles)} />
                    <DefinitionRow label="Total roles tracked" value={String(counts.total_roles)} />
                </CardContent>
            </Card>
        </div>
    );
}

function DefinitionRow({ label, value, link }: { label: string; value: string | null; link?: boolean }) {
    return (
        <div className="flex flex-col gap-0.5 sm:flex-row sm:items-baseline sm:justify-between">
            <span className="text-xs uppercase tracking-wide text-muted-foreground">{label}</span>
            {value === null || value === '' ? (
                <span className="text-xs text-muted-foreground">—</span>
            ) : link ? (
                <a
                    href={value}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="break-all text-sm text-foreground hover:underline"
                >
                    {value}
                </a>
            ) : (
                <span className="break-all text-sm">{value}</span>
            )}
        </div>
    );
}

function RolesTable({ roles }: { roles: PaginatedRoles }) {
    if (roles.data.length === 0) {
        return (
            <Card>
                <CardContent className="px-6 py-12 text-center text-sm text-muted-foreground">
                    No roles tracked yet. Run <code className="rounded bg-muted px-1 py-0.5">scrape:company</code> to fetch.
                </CardContent>
            </Card>
        );
    }

    return (
        <Card>
            <CardContent className="p-0">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Title</TableHead>
                            <TableHead>Workplace</TableHead>
                            <TableHead>Comp</TableHead>
                            <TableHead>Source</TableHead>
                            <TableHead>Last seen</TableHead>
                            <TableHead className="text-right">State</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {roles.data.map((role) => {
                            const comp = formatComp(role);
                            return (
                                <TableRow key={role.id}>
                                    <TableCell>
                                        <div className="flex flex-col">
                                            {role.url ? (
                                                <a
                                                    href={role.url}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    className="font-medium hover:underline"
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
                                            <span className="text-sm">
                                                {role.workplace_type ? humanize(role.workplace_type) : '—'}
                                            </span>
                                            {role.location ? (
                                                <span className="text-xs text-muted-foreground">{role.location}</span>
                                            ) : null}
                                        </div>
                                    </TableCell>
                                    <TableCell>
                                        {comp ? <span className="text-sm">{comp}</span> : <span className="text-xs text-muted-foreground">—</span>}
                                    </TableCell>
                                    <TableCell>
                                        <span className="text-xs text-muted-foreground">{role.source}</span>
                                    </TableCell>
                                    <TableCell>
                                        <span className="text-xs text-muted-foreground">
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
    );
}

function Timeline({ items }: { items: TimelineItem[] }) {
    if (items.length === 0) {
        return (
            <Card>
                <CardContent className="px-6 py-12 text-center text-sm text-muted-foreground">
                    Nothing has happened here yet.
                </CardContent>
            </Card>
        );
    }

    return (
        <Card>
            <CardContent className="p-0">
                <ol className="divide-y">
                    {items.map((item) => (
                        <li key={item.id} className="flex flex-col gap-1 px-6 py-4 sm:flex-row sm:items-baseline sm:justify-between">
                            <div className="flex-1 space-y-1">
                                <div className="flex items-center gap-2">
                                    <Badge variant={EVENT_VARIANT[item.type] ?? 'secondary'}>{humanize(item.type)}</Badge>
                                    <span className="text-sm">{item.title}</span>
                                </div>
                                {item.body ? <p className="text-xs text-muted-foreground">{item.body}</p> : null}
                            </div>
                            <span className="text-xs text-muted-foreground">
                                {item.at ? new Date(item.at).toLocaleString() : ''}
                            </span>
                        </li>
                    ))}
                </ol>
            </CardContent>
        </Card>
    );
}
