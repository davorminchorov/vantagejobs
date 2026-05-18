import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { cn } from '@/lib/utils';

interface CompanyRow {
    id: number;
    name: string;
    website: string | null;
    domain: string | null;
    ats_type: string | null;
    ats_slug: string | null;
    tier: 'A' | 'B' | 'C' | 'graveyard';
    status: string;
    last_checked_at: string | null;
    open_roles_count: number;
}

interface PaginatedCompanies {
    data: CompanyRow[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links: { url: string | null; label: string; active: boolean }[];
}

interface Filters {
    tier: string | null;
    status: string | null;
    ats_type: string | null;
    has_open_roles: boolean | null;
    last_checked: string | null;
    q: string | null;
}

interface Options {
    tiers: string[];
    statuses: string[];
    ats_types: string[];
    last_checked: string[];
}

interface Props {
    companies: PaginatedCompanies;
    filters: Filters;
    options: Options;
}

const TIER_VARIANT: Record<string, 'default' | 'secondary' | 'outline'> = {
    A: 'default',
    B: 'secondary',
    C: 'outline',
    graveyard: 'outline',
};

const LAST_CHECKED_LABELS: Record<string, string> = {
    never: 'Never',
    last_24h: 'Last 24h',
    last_7d: 'Last 7 days',
    last_30d: 'Last 30 days',
    older_than_30d: 'Older than 30 days',
};

function toQueryParams(filters: Filters): Record<string, string> {
    const params: Record<string, string> = {};
    if (filters.tier) params.tier = filters.tier;
    if (filters.status) params.status = filters.status;
    if (filters.ats_type) params.ats_type = filters.ats_type;
    if (filters.has_open_roles !== null) params.has_open_roles = filters.has_open_roles ? '1' : '0';
    if (filters.last_checked) params.last_checked = filters.last_checked;
    if (filters.q) params.q = filters.q;
    return params;
}

export default function CompaniesIndexPage({ companies, filters, options }: Props) {
    const [local, setLocal] = useState<Filters>(filters);
    const isFirstRender = useRef(true);

    useEffect(() => {
        if (isFirstRender.current) {
            isFirstRender.current = false;
            return;
        }

        const handle = window.setTimeout(() => {
            router.get('/companies', toQueryParams(local), {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            });
        }, 300);

        return () => window.clearTimeout(handle);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [local.tier, local.status, local.ats_type, local.has_open_roles, local.last_checked, local.q]);

    function setFilter<K extends keyof Filters>(key: K, value: Filters[K]) {
        setLocal((prev) => ({ ...prev, [key]: value }));
    }

    function clearFilters() {
        setLocal({
            tier: null,
            status: null,
            ats_type: null,
            has_open_roles: null,
            last_checked: null,
            q: null,
        });
    }

    const hasAnyFilter =
        local.tier || local.status || local.ats_type || local.has_open_roles !== null || local.last_checked || local.q;

    return (
        <AuthenticatedLayout>
            <Head title="Companies" />
            <div className="space-y-6">
                <header className="flex items-end justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">Companies</h1>
                        <p className="text-sm text-muted-foreground">
                            {companies.total === 0
                                ? 'No companies match the current filters.'
                                : `${companies.total} ${companies.total === 1 ? 'company' : 'companies'} on the watch list.`}
                        </p>
                    </div>
                </header>

                <Card>
                    <CardHeader className="space-y-4">
                        <CardTitle className="text-sm font-medium">Filters</CardTitle>
                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
                            <FilterSelect
                                label="Tier"
                                value={local.tier}
                                onChange={(v) => setFilter('tier', v)}
                                options={options.tiers.map((t) => ({ value: t, label: t === 'graveyard' ? 'Graveyard' : `Tier ${t}` }))}
                            />
                            <FilterSelect
                                label="Status"
                                value={local.status}
                                onChange={(v) => setFilter('status', v)}
                                options={options.statuses.map((s) => ({ value: s, label: humanize(s) }))}
                            />
                            <FilterSelect
                                label="ATS"
                                value={local.ats_type}
                                onChange={(v) => setFilter('ats_type', v)}
                                options={options.ats_types.map((a) => ({ value: a, label: humanize(a) }))}
                            />
                            <FilterSelect
                                label="Last checked"
                                value={local.last_checked}
                                onChange={(v) => setFilter('last_checked', v)}
                                options={options.last_checked.map((l) => ({ value: l, label: LAST_CHECKED_LABELS[l] ?? l }))}
                            />
                            <FilterSelect
                                label="Open roles"
                                value={local.has_open_roles === null ? null : local.has_open_roles ? '1' : '0'}
                                onChange={(v) => setFilter('has_open_roles', v === null ? null : v === '1')}
                                options={[
                                    { value: '1', label: 'Has open roles' },
                                    { value: '0', label: 'No open roles' },
                                ]}
                            />
                            <div className="flex flex-col gap-1.5">
                                <label className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                                    Search
                                </label>
                                <Input
                                    type="search"
                                    placeholder="Name, domain, slug…"
                                    value={local.q ?? ''}
                                    onChange={(e) => setFilter('q', e.target.value === '' ? null : e.target.value)}
                                />
                            </div>
                        </div>
                        {hasAnyFilter ? (
                            <div>
                                <Button variant="ghost" size="sm" onClick={clearFilters}>
                                    Clear filters
                                </Button>
                            </div>
                        ) : null}
                    </CardHeader>
                    <CardContent className="p-0">
                        {companies.data.length === 0 ? (
                            <div className="px-6 py-12 text-center text-sm text-muted-foreground">
                                No matching companies. Try clearing some filters.
                            </div>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Name</TableHead>
                                        <TableHead>Tier</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead>ATS</TableHead>
                                        <TableHead>Open roles</TableHead>
                                        <TableHead>Last checked</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {companies.data.map((company) => (
                                        <TableRow key={company.id}>
                                            <TableCell>
                                                <div className="flex flex-col">
                                                    <span className="font-medium">{company.name}</span>
                                                    {company.domain ? (
                                                        <span className="text-xs text-muted-foreground">
                                                            {company.domain}
                                                        </span>
                                                    ) : null}
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                <Badge variant={TIER_VARIANT[company.tier] ?? 'outline'}>
                                                    {company.tier === 'graveyard' ? 'Graveyard' : `Tier ${company.tier}`}
                                                </Badge>
                                            </TableCell>
                                            <TableCell>
                                                <span className="text-sm">{humanize(company.status)}</span>
                                            </TableCell>
                                            <TableCell>
                                                {company.ats_type ? (
                                                    <div className="flex flex-col">
                                                        <span className="text-sm">{humanize(company.ats_type)}</span>
                                                        {company.ats_slug ? (
                                                            <span className="text-xs text-muted-foreground">
                                                                {company.ats_slug}
                                                            </span>
                                                        ) : null}
                                                    </div>
                                                ) : (
                                                    <span className="text-xs text-muted-foreground">—</span>
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                {company.open_roles_count > 0 ? (
                                                    <Badge variant="success">{company.open_roles_count}</Badge>
                                                ) : (
                                                    <span className="text-xs text-muted-foreground">0</span>
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                <span className="text-xs text-muted-foreground">
                                                    {company.last_checked_at
                                                        ? new Date(company.last_checked_at).toLocaleString()
                                                        : 'Never'}
                                                </span>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </CardContent>
                </Card>

                {companies.last_page > 1 ? <Pagination links={companies.links} /> : null}
            </div>
        </AuthenticatedLayout>
    );
}

function humanize(value: string): string {
    return value.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

interface FilterSelectProps {
    label: string;
    value: string | null;
    onChange: (value: string | null) => void;
    options: { value: string; label: string }[];
}

function FilterSelect({ label, value, onChange, options }: FilterSelectProps) {
    return (
        <div className="flex flex-col gap-1.5">
            <label className="text-xs font-medium uppercase tracking-wide text-muted-foreground">{label}</label>
            <select
                value={value ?? ''}
                onChange={(e) => onChange(e.target.value === '' ? null : e.target.value)}
                className={cn(
                    'h-9 rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm',
                    'focus-visible:ring-ring focus-visible:outline-none focus-visible:ring-2',
                )}
            >
                <option value="">All</option>
                {options.map((option) => (
                    <option key={option.value} value={option.value}>
                        {option.label}
                    </option>
                ))}
            </select>
        </div>
    );
}

interface PaginationProps {
    links: { url: string | null; label: string; active: boolean }[];
}

function Pagination({ links }: PaginationProps) {
    return (
        <nav className="flex items-center justify-end gap-1" aria-label="Pagination">
            {links.map((link, index) => {
                const label = link.label.replace('&laquo;', '‹').replace('&raquo;', '›');
                if (link.url === null) {
                    return (
                        <span
                            key={`${label}-${index}`}
                            className="px-2 py-1 text-sm text-muted-foreground"
                            dangerouslySetInnerHTML={{ __html: label }}
                        />
                    );
                }
                return (
                    <Link
                        key={`${label}-${index}`}
                        href={link.url}
                        preserveScroll
                        preserveState
                        className={cn(
                            'rounded-md border px-3 py-1 text-sm transition',
                            link.active
                                ? 'border-primary bg-primary text-primary-foreground'
                                : 'hover:bg-accent hover:text-accent-foreground',
                        )}
                        dangerouslySetInnerHTML={{ __html: label }}
                    />
                );
            })}
        </nav>
    );
}
