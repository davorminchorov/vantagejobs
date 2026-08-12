import type { FormDataConvertible } from '@inertiajs/core';
import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { useListKeyboardNav } from '@/hooks/useListKeyboardNav';
import BulkActionBar from '@/components/bulk/BulkActionBar';
import SaveViewButton from '@/components/saved-views/SaveViewButton';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetTrigger } from '@/components/ui/sheet';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { RelativeDate } from '@/lib/dates';
import { cn } from '@/lib/utils';

interface CompanyRow {
    id: number;
    name: string;
    website: string | null;
    domain: string | null;
    ats_type: string | null;
    ats_slug: string | null;
    ats_status: string;
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

export interface CompanyFilters {
    tier: string[];
    status: string[];
    ats_type: string[];
    stage: string[];
    size: string[];
    remote_policy: string[];
    primary_stack: string[];
    has_open_roles: boolean | null;
    last_checked: string | null;
    domain: string | null;
    q: string | null;
    recently_funded: boolean | null;
}

interface Options {
    tiers: string[];
    statuses: string[];
    ats_types: string[];
    last_checked: string[];
    stages: string[];
    sizes: string[];
    remote_policies: string[];
    primary_stacks: string[];
}

interface Props {
    companies: PaginatedCompanies;
    filters: CompanyFilters;
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

const EMPTY_FILTERS: CompanyFilters = {
    tier: [],
    status: [],
    ats_type: [],
    stage: [],
    size: [],
    remote_policy: [],
    primary_stack: [],
    has_open_roles: null,
    last_checked: null,
    domain: null,
    q: null,
    recently_funded: null,
};

interface QuickChip {
    readonly key: string;
    readonly label: string;
    readonly filters: Partial<CompanyFilters>;
}

const QUICK_CHIPS: ReadonlyArray<QuickChip> = [
    { key: 'a-tier', label: 'A-tier', filters: { tier: ['A'] } },
    { key: 'has-open-roles', label: 'Hiring now', filters: { has_open_roles: true } },
    { key: 'stale-7d', label: 'Stale > 7d', filters: { last_checked: 'older_than_30d' } },
    { key: 'ats-detected', label: 'ATS detected', filters: { ats_type: ['greenhouse', 'lever', 'ashby', 'pinpoint', 'bamboohr', 'rippling'] } },
    { key: 'recently-funded', label: 'Funded in last 18mo', filters: { recently_funded: true } },
];

export function companyFiltersToQuery(filters: CompanyFilters): Record<string, string | string[]> {
    const params: Record<string, string | string[]> = {};
    if (filters.tier.length > 0) params.tier = filters.tier;
    if (filters.status.length > 0) params.status = filters.status;
    if (filters.ats_type.length > 0) params.ats_type = filters.ats_type;
    if (filters.stage.length > 0) params.stage = filters.stage;
    if (filters.size.length > 0) params.size = filters.size;
    if (filters.remote_policy.length > 0) params.remote_policy = filters.remote_policy;
    if (filters.primary_stack.length > 0) params.primary_stack = filters.primary_stack;
    if (filters.has_open_roles !== null) params.has_open_roles = filters.has_open_roles ? '1' : '0';
    if (filters.last_checked) params.last_checked = filters.last_checked;
    if (filters.domain) params.domain = filters.domain;
    if (filters.q) params.q = filters.q;
    if (filters.recently_funded !== null) params.recently_funded = filters.recently_funded ? '1' : '0';
    return params;
}

function toggleArrayValue(current: string[], value: string): string[] {
    return current.includes(value) ? current.filter((v) => v !== value) : [...current, value];
}

function chipMatches(active: CompanyFilters, chip: QuickChip): boolean {
    return Object.entries(chip.filters).every(([k, v]) => {
        const actual = (active as unknown as Record<string, unknown>)[k];
        if (Array.isArray(v)) {
            return Array.isArray(actual) && v.length === actual.length && v.every((x) => (actual as string[]).includes(x));
        }
        return actual === v;
    });
}

export default function CompaniesIndexPage({ companies, filters, options }: Props) {
    const [local, setLocal] = useState<CompanyFilters>(filters);
    const [selectedIds, setSelectedIds] = useState<number[]>([]);
    const isFirstRender = useRef(true);

    useEffect(() => {
        setLocal(filters);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [JSON.stringify(filters)]);

    // Drop any selection that points at rows no longer on the page.
    useEffect(() => {
        const pageIds = new Set(companies.data.map((c) => c.id));
        setSelectedIds((prev) => prev.filter((id) => pageIds.has(id)));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [companies.data.map((c) => c.id).join(',')]);

    function toggleRow(id: number) {
        setSelectedIds((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]));
    }

    const allOnPageSelected = companies.data.length > 0 && companies.data.every((c) => selectedIds.includes(c.id));

    function toggleAllOnPage() {
        setSelectedIds(allOnPageSelected ? [] : companies.data.map((c) => c.id));
    }

    const { registerItem, isFocused, setFocusIndex } = useListKeyboardNav({
        itemCount: companies.data.length,
        actions: {
            Enter: (index) => {
                const company = companies.data[index];
                if (company) {
                    router.visit(`/companies/${company.id}`);
                }
            },
            x: (index) => {
                const company = companies.data[index];
                if (company) {
                    toggleRow(company.id);
                }
            },
        },
    });

    useEffect(() => {
        if (isFirstRender.current) {
            isFirstRender.current = false;
            return;
        }

        const handle = window.setTimeout(() => {
            router.get('/companies', companyFiltersToQuery(local), {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            });
        }, 300);

        return () => window.clearTimeout(handle);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [JSON.stringify(local)]);

    function patchFilters(patch: Partial<CompanyFilters>) {
        setLocal((prev) => ({ ...prev, ...patch }));
    }

    function clearFilters() {
        setLocal(EMPTY_FILTERS);
    }

    function applyChip(chip: QuickChip) {
        if (chipMatches(local, chip)) {
            // toggle off — clear just the dimensions this chip controls.
            const cleared: Partial<CompanyFilters> = {};
            for (const k of Object.keys(chip.filters)) {
                cleared[k as keyof CompanyFilters] = (EMPTY_FILTERS as unknown as Record<string, unknown>)[k] as never;
            }
            patchFilters(cleared);
            return;
        }
        patchFilters(chip.filters);
    }

    const hasAnyFilter = useMemo(() => {
        return (
            local.tier.length > 0 ||
            local.status.length > 0 ||
            local.ats_type.length > 0 ||
            local.stage.length > 0 ||
            local.size.length > 0 ||
            local.remote_policy.length > 0 ||
            local.primary_stack.length > 0 ||
            local.has_open_roles !== null ||
            local.last_checked !== null ||
            local.domain !== null ||
            local.q !== null ||
            local.recently_funded !== null
        );
    }, [local]);

    const filterPanel = (
        <FilterPanel local={local} options={options} patchFilters={patchFilters} onClear={clearFilters} hasAny={hasAnyFilter} />
    );

    return (
        <AuthenticatedLayout>
            <Head title="Companies" />
            <div className="space-y-6">
                <header className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">Companies</h1>
                        <p className="text-sm text-muted-foreground">
                            {companies.total === 0
                                ? 'No companies match the current filters.'
                                : `${companies.total} ${companies.total === 1 ? 'company' : 'companies'} on the watch list.`}
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <SaveViewButton
                            entity="companies"
                            filters={local as unknown as Record<string, FormDataConvertible>}
                            disabled={!hasAnyFilter}
                        />
                        <Button asChild variant="outline">
                            <Link href="/import">Bulk import</Link>
                        </Button>
                        <Button asChild>
                            <Link href="/companies/create">Add company</Link>
                        </Button>
                    </div>
                </header>

                <div className="flex flex-wrap items-center gap-2" aria-label="Quick filters">
                    {QUICK_CHIPS.map((chip) => {
                        const active = chipMatches(local, chip);
                        return (
                            <Button
                                key={chip.key}
                                size="sm"
                                variant={active ? 'default' : 'outline'}
                                onClick={() => applyChip(chip)}
                                className="rounded-full"
                            >
                                {chip.label}
                            </Button>
                        );
                    })}
                </div>

                <div className="grid gap-6 md:grid-cols-[18rem_1fr]">
                    <aside className="hidden min-w-0 md:block">{filterPanel}</aside>

                    <div className="min-w-0 space-y-3">
                        <div className="flex items-center justify-between md:hidden">
                            <Sheet>
                                <SheetTrigger asChild>
                                    <Button variant="outline" size="sm">
                                        Filters{hasAnyFilter ? ' •' : ''}
                                    </Button>
                                </SheetTrigger>
                                <SheetContent side="left" className="w-80 overflow-y-auto">
                                    <SheetHeader>
                                        <SheetTitle>Filters</SheetTitle>
                                    </SheetHeader>
                                    <div className="mt-4">{filterPanel}</div>
                                </SheetContent>
                            </Sheet>
                        </div>

                        <Card className="rounded-2xl border-border">
                            <CardContent className="p-0">
                                {companies.data.length === 0 ? (
                                    <div className="px-6 py-12 text-center text-sm text-muted-foreground">
                                        No matching companies. Try clearing some filters.
                                    </div>
                                ) : (
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead className="w-10">
                                                    <input
                                                        type="checkbox"
                                                        className="size-4 rounded border-input"
                                                        aria-label="Select all companies on this page"
                                                        checked={allOnPageSelected}
                                                        onChange={toggleAllOnPage}
                                                    />
                                                </TableHead>
                                                <TableHead>Name</TableHead>
                                                <TableHead>Tier</TableHead>
                                                <TableHead>Status</TableHead>
                                                <TableHead>ATS</TableHead>
                                                <TableHead>Open roles</TableHead>
                                                <TableHead>Last checked</TableHead>
                                                <TableHead className="text-right">Actions</TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {companies.data.map((company, index) => (
                                                <TableRow
                                                    key={company.id}
                                                    ref={registerItem(index)}
                                                    tabIndex={0}
                                                    aria-label={`${company.name}, tier ${company.tier}, ${company.open_roles_count} open roles`}
                                                    onFocus={() => setFocusIndex(index)}
                                                    data-state={selectedIds.includes(company.id) ? 'selected' : undefined}
                                                    className={cn(
                                                        'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring',
                                                        isFocused(index) && 'bg-accent/50',
                                                    )}
                                                >
                                                    <TableCell>
                                                        <input
                                                            type="checkbox"
                                                            className="size-4 rounded border-input"
                                                            aria-label={`Select ${company.name}`}
                                                            checked={selectedIds.includes(company.id)}
                                                            onChange={() => toggleRow(company.id)}
                                                        />
                                                    </TableCell>
                                                    <TableCell>
                                                        <div className="flex flex-col">
                                                            <Link
                                                                href={`/companies/${company.id}`}
                                                                className="font-medium hover:underline"
                                                            >
                                                                {company.name}
                                                            </Link>
                                                            {company.domain ? (
                                                                <span className="font-mono text-xs text-muted-foreground">
                                                                    {bareDomain(company.domain)}
                                                                </span>
                                                            ) : null}
                                                        </div>
                                                    </TableCell>
                                                    <TableCell>
                                                        <Badge variant={TIER_VARIANT[company.tier] ?? 'outline'}>
                                                            {company.tier === 'graveyard'
                                                                ? 'Graveyard'
                                                                : `Tier ${company.tier}`}
                                                        </Badge>
                                                    </TableCell>
                                                    <TableCell>
                                                        <span className="text-sm">{humanize(company.status)}</span>
                                                    </TableCell>
                                                    <TableCell>
                                                        {company.ats_type ? (
                                                            <div className="flex flex-col gap-0.5">
                                                                <span className="text-sm">{humanize(company.ats_type)}</span>
                                                                {company.ats_slug ? (
                                                                    <span className="font-mono text-xs text-muted-foreground">
                                                                        {company.ats_slug}
                                                                    </span>
                                                                ) : null}
                                                                {company.ats_status === 'redetect_failed' ? (
                                                                    <Badge variant="warning" className="w-fit">
                                                                        redetection failed
                                                                    </Badge>
                                                                ) : null}
                                                            </div>
                                                        ) : (
                                                            <Badge variant="warning">no ATS</Badge>
                                                        )}
                                                    </TableCell>
                                                    <TableCell>
                                                        {company.open_roles_count > 0 ? (
                                                            <Badge variant="success" className="font-mono tabular-nums">
                                                                {company.open_roles_count}
                                                            </Badge>
                                                        ) : (
                                                            <span className="font-mono text-xs tabular-nums text-muted-foreground">
                                                                0
                                                            </span>
                                                        )}
                                                    </TableCell>
                                                    <TableCell>
                                                        {company.last_checked_at ? (
                                                            <RelativeDate
                                                                iso={company.last_checked_at}
                                                                className="font-mono text-xs tabular-nums text-muted-foreground"
                                                            />
                                                        ) : (
                                                            <span className="font-mono text-xs tabular-nums text-muted-foreground">Never</span>
                                                        )}
                                                    </TableCell>
                                                    <TableCell className="text-right">
                                                        <Link
                                                            href={`/companies/${company.id}/edit`}
                                                            className="text-xs font-medium text-muted-foreground hover:text-foreground"
                                                        >
                                                            Edit
                                                        </Link>
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
                </div>

                <BulkActionBar
                    entity="company"
                    selectedIds={selectedIds}
                    onClear={() => setSelectedIds([])}
                    only={['companies', 'flash']}
                />
            </div>
        </AuthenticatedLayout>
    );
}

function humanize(value: string): string {
    return value.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

/** Strips a leading protocol and trailing slash so every domain renders bare, e.g. "https://hospitable.com/" -> "hospitable.com". */
function bareDomain(domain: string): string {
    return domain.replace(/^[a-z][a-z0-9+.-]*:\/\//i, '').replace(/\/+$/, '');
}

interface FilterPanelProps {
    local: CompanyFilters;
    options: Options;
    patchFilters: (patch: Partial<CompanyFilters>) => void;
    onClear: () => void;
    hasAny: boolean;
}

function FilterPanel({ local, options, patchFilters, onClear, hasAny }: FilterPanelProps) {
    return (
        <Card className="rounded-2xl border-border">
            <CardHeader className="space-y-4">
                <div className="flex items-center justify-between">
                    <CardTitle className="text-sm font-medium">Filters</CardTitle>
                    {hasAny ? (
                        <Button variant="ghost" size="sm" onClick={onClear}>
                            Clear
                        </Button>
                    ) : null}
                </div>
            </CardHeader>
            <CardContent className="space-y-4">
                <div className="space-y-1.5">
                    <label className="font-mono text-xs font-medium uppercase tracking-wide text-muted-foreground">
                        Search
                    </label>
                    <Input
                        type="search"
                        placeholder="Name, domain, slug…"
                        value={local.q ?? ''}
                        onChange={(e) => patchFilters({ q: e.target.value === '' ? null : e.target.value })}
                    />
                </div>
                <MultiCheckboxGroup
                    label="Tier"
                    selected={local.tier}
                    onChange={(next) => patchFilters({ tier: next })}
                    options={options.tiers.map((t) => ({ value: t, label: t === 'graveyard' ? 'Graveyard' : `Tier ${t}` }))}
                />
                <MultiCheckboxGroup
                    label="Status"
                    selected={local.status}
                    onChange={(next) => patchFilters({ status: next })}
                    options={options.statuses.map((s) => ({ value: s, label: humanize(s) }))}
                />
                <MultiCheckboxGroup
                    label="ATS"
                    selected={local.ats_type}
                    onChange={(next) => patchFilters({ ats_type: next })}
                    options={options.ats_types.map((a) => ({ value: a, label: humanize(a) }))}
                />
                {options.stages.length > 0 ? (
                    <MultiCheckboxGroup
                        label="Funding stage"
                        selected={local.stage}
                        onChange={(next) => patchFilters({ stage: next })}
                        options={options.stages.map((s) => ({ value: s, label: s }))}
                    />
                ) : null}
                <SingleSelect
                    label="Recently funded"
                    value={local.recently_funded === null ? null : local.recently_funded ? '1' : '0'}
                    onChange={(v) => patchFilters({ recently_funded: v === null ? null : v === '1' })}
                    options={[
                        { value: '1', label: 'Raised in last 18 months' },
                        { value: '0', label: 'Not in last 18 months' },
                    ]}
                />
                <SingleSelect
                    label="Last checked"
                    value={local.last_checked}
                    onChange={(v) => patchFilters({ last_checked: v })}
                    options={options.last_checked.map((l) => ({ value: l, label: LAST_CHECKED_LABELS[l] ?? l }))}
                />
                <SingleSelect
                    label="Open roles"
                    value={local.has_open_roles === null ? null : local.has_open_roles ? '1' : '0'}
                    onChange={(v) => patchFilters({ has_open_roles: v === null ? null : v === '1' })}
                    options={[
                        { value: '1', label: 'Has open roles' },
                        { value: '0', label: 'No open roles' },
                    ]}
                />
                <div className="space-y-1.5">
                    <label className="font-mono text-xs font-medium uppercase tracking-wide text-muted-foreground">
                        Domain contains
                    </label>
                    <Input
                        type="search"
                        placeholder="example.com"
                        value={local.domain ?? ''}
                        onChange={(e) => patchFilters({ domain: e.target.value === '' ? null : e.target.value })}
                    />
                </div>
            </CardContent>
        </Card>
    );
}

interface MultiCheckboxGroupProps {
    label: string;
    selected: string[];
    onChange: (next: string[]) => void;
    options: { value: string; label: string }[];
}

function MultiCheckboxGroup({ label, selected, onChange, options }: MultiCheckboxGroupProps) {
    return (
        <fieldset className="space-y-1.5">
            <legend className="font-mono text-xs font-medium uppercase tracking-wide text-muted-foreground">{label}</legend>
            <div className="flex flex-col gap-1">
                {options.map((option) => {
                    const checked = selected.includes(option.value);
                    return (
                        <label key={option.value} className="flex cursor-pointer items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                className="size-4 rounded border-input"
                                checked={checked}
                                onChange={() => onChange(toggleArrayValue(selected, option.value))}
                            />
                            <span>{option.label}</span>
                        </label>
                    );
                })}
            </div>
        </fieldset>
    );
}

interface SingleSelectProps {
    label: string;
    value: string | null;
    onChange: (value: string | null) => void;
    options: { value: string; label: string }[];
}

function SingleSelect({ label, value, onChange, options }: SingleSelectProps) {
    return (
        <div className="flex flex-col gap-1.5">
            <label className="font-mono text-xs font-medium uppercase tracking-wide text-muted-foreground">{label}</label>
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
                            className="px-2 py-1 font-mono text-sm tabular-nums text-muted-foreground"
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
                            'rounded-md border px-3 py-1 font-mono text-sm tabular-nums transition',
                            link.active
                                ? 'border-primary bg-primary text-primary-foreground'
                                : 'border-border hover:bg-accent hover:text-accent-foreground',
                        )}
                        dangerouslySetInnerHTML={{ __html: label }}
                    />
                );
            })}
        </nav>
    );
}
