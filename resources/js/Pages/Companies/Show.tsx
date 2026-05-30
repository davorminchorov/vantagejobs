import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import HiringTrendChart from '@/components/charts/HiringTrendChart';
import TagInput, { type TagSummary } from '@/components/companies/TagInput';
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

export default function ShowCompanyPage({
    company,
    roles,
    timeline,
    tags,
    counts,
    graveyard,
    graveyard_preset_tags,
    hiring_trend,
    quality,
}: Props) {
    const [tab, setTab] = useState<'overview' | 'roles' | 'timeline'>('overview');

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
                    <div className="flex items-center gap-2">
                        <Button asChild variant="outline">
                            <Link href={`/companies/${company.id}/edit`}>Edit</Link>
                        </Button>
                        <GraveyardControls
                            companyId={company.id}
                            isActive={company.is_active}
                            presetTags={graveyard_preset_tags}
                        />
                    </div>
                </header>

                {graveyard ? <GraveyardBanner info={graveyard} /> : null}

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
                                <CardTitle className="font-mono text-sm font-semibold uppercase tracking-wide text-muted-foreground">
                                    Hiring volume (90 days)
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <HiringTrendChart data={hiring_trend} />
                            </CardContent>
                        </Card>
                        <Overview company={company} counts={counts} tags={tags} />
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

function Overview({ company, counts, tags }: { company: CompanyDetail; counts: Counts; tags: TagSummary[] }) {
    return (
        <div className="grid gap-4 lg:grid-cols-2">
            <Card className="rounded-2xl lg:col-span-2">
                <CardHeader>
                    <CardTitle className="font-mono text-sm font-semibold uppercase tracking-wide text-muted-foreground">
                        Tags
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <TagInput companyId={company.id} tags={tags} />
                </CardContent>
            </Card>

            <Card className="rounded-2xl">
                <CardHeader>
                    <CardTitle className="font-mono text-sm font-semibold uppercase tracking-wide text-muted-foreground">
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

            <Card className="rounded-2xl">
                <CardHeader>
                    <CardTitle className="font-mono text-sm font-semibold uppercase tracking-wide text-muted-foreground">
                        ATS
                    </CardTitle>
                </CardHeader>
                <CardContent className="space-y-2 text-sm">
                    <DefinitionRow label="Type" value={company.ats_type ? humanize(company.ats_type) : null} />
                    <DefinitionRow label="Slug" value={company.ats_slug} />
                    <DefinitionRow label="ETag" value={company.etag} mono />
                    <DefinitionRow label="Last-Modified" value={company.last_modified} mono />
                </CardContent>
            </Card>

            <Card className="rounded-2xl">
                <CardHeader>
                    <CardTitle className="font-mono text-sm font-semibold uppercase tracking-wide text-muted-foreground">
                        Operational
                    </CardTitle>
                </CardHeader>
                <CardContent className="space-y-2 text-sm">
                    <DefinitionRow
                        label="Last checked"
                        value={company.last_checked_at ? new Date(company.last_checked_at).toLocaleString() : 'Never'}
                        mono
                    />
                    <DefinitionRow
                        label="Added"
                        value={company.created_at ? new Date(company.created_at).toLocaleDateString() : null}
                        mono
                    />
                </CardContent>
            </Card>

            <Card className="rounded-2xl">
                <CardHeader>
                    <CardTitle className="font-mono text-sm font-semibold uppercase tracking-wide text-muted-foreground">
                        Counts
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
    value: string | null;
    link?: boolean;
    mono?: boolean;
}) {
    return (
        <div className="flex flex-col gap-0.5 sm:flex-row sm:items-baseline sm:justify-between">
            <span className="font-mono text-xs uppercase tracking-wide text-muted-foreground">{label}</span>
            {value === null || value === '' ? (
                <span className="text-sm text-muted-foreground">—</span>
            ) : link ? (
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

function RolesTable({ roles }: { roles: PaginatedRoles }) {
    if (roles.data.length === 0) {
        return (
            <Card className="rounded-2xl">
                <CardContent className="px-6 py-12 text-center text-sm text-muted-foreground">
                    No roles tracked yet. Run <code className="rounded bg-muted px-1 py-0.5 font-mono">scrape:company</code> to fetch.
                </CardContent>
            </Card>
        );
    }

    return (
        <Card className="rounded-2xl">
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
                                            <span className="text-sm">
                                                {role.workplace_type ? humanize(role.workplace_type) : '—'}
                                            </span>
                                            {role.location ? (
                                                <span className="text-xs text-muted-foreground">{role.location}</span>
                                            ) : null}
                                        </div>
                                    </TableCell>
                                    <TableCell>
                                        {comp ? <span className="font-mono text-sm tabular-nums">{comp}</span> : <span className="text-sm text-muted-foreground">—</span>}
                                    </TableCell>
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
    );
}

function Timeline({ items }: { items: TimelineItem[] }) {
    if (items.length === 0) {
        return (
            <Card className="rounded-2xl">
                <CardContent className="px-6 py-12 text-center text-sm text-muted-foreground">
                    Nothing has happened here yet.
                </CardContent>
            </Card>
        );
    }

    return (
        <Card className="rounded-2xl">
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
                            <span className="font-mono text-xs tabular-nums text-muted-foreground">
                                {item.at ? new Date(item.at).toLocaleString() : ''}
                            </span>
                        </li>
                    ))}
                </ol>
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
    isActive,
    presetTags,
}: {
    companyId: number;
    isActive: boolean;
    presetTags: string[];
}) {
    const [open, setOpen] = useState(false);
    const demote = useForm<{ reason: string; tags: string[] }>({ reason: '', tags: [] });
    const restore = useForm({});

    if (!isActive) {
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
                <CardTitle className="font-mono text-sm font-semibold uppercase tracking-wide text-muted-foreground">
                    Quality score
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
                <table className="w-full text-sm">
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
                <p className="text-sm text-muted-foreground">
                    Score starts at the baseline and is adjusted by each factor. Positive
                    factors max at their weight; penalties subtract up to their weight.
                </p>
            </CardContent>
        </Card>
    );
}
