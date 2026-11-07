import type { FormDataConvertible } from '@inertiajs/core';
import { Head, Link, router } from '@inertiajs/react';
import { MoreHorizontal } from 'lucide-react';
import { useEffect, useRef, useState, useSyncExternalStore } from 'react';

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { useListKeyboardNav } from '@/hooks/useListKeyboardNav';
import BulkActionBar from '@/components/bulk/BulkActionBar';
import CompanyFilterBar from '@/components/companies/CompanyFilterBar';
import CompanySignals, { computeSignals } from '@/components/companies/CompanySignals';
import Sparkline from '@/components/companies/Sparkline';
import {
    companyFiltersToQuery,
    EMPTY_FILTERS,
    hasActiveFilters,
    tierLabel,
    type CompanyFilterOptions,
    type CompanyFilters,
} from '@/components/companies/filters';
import SaveViewButton from '@/components/saved-views/SaveViewButton';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { cn } from '@/lib/utils';

interface CompanyRow {
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
    last_checked_at: string | null;
    funding_last_raised_at: string | null;
    open_roles_count: number;
    new_roles_7d: number;
    quality_score: number | null;
    has_recent_layoff_event: boolean | null;
    hiring_trend: (number | null)[];
    latest_open_posted_at: string | null;
}

interface PaginatedCompanies {
    data: CompanyRow[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links: { url: string | null; label: string; active: boolean }[];
}

interface Props {
    companies: PaginatedCompanies;
    filters: CompanyFilters;
    options: CompanyFilterOptions;
}

const TIER_VARIANT: Record<string, 'default' | 'secondary' | 'outline'> = {
    A: 'default',
    B: 'secondary',
    C: 'outline',
    graveyard: 'outline',
};

const DESKTOP_QUERY = '(min-width: 40rem)';

function subscribeToViewport(onChange: () => void): () => void {
    const media = window.matchMedia(DESKTOP_QUERY);
    media.addEventListener('change', onChange);
    return () => media.removeEventListener('change', onChange);
}

/** True at Tailwind's `sm` breakpoint and up, where the table layout fits. */
function useIsTableLayout(): boolean {
    return useSyncExternalStore(
        subscribeToViewport,
        () => window.matchMedia(DESKTOP_QUERY).matches,
        () => true,
    );
}

/** Strips a leading protocol and trailing slash so every domain renders bare, e.g. "https://hospitable.com/" -> "hospitable.com". */
function bareDomain(domain: string): string {
    return domain.replace(/^[a-z][a-z0-9+.-]*:\/\//i, '').replace(/\/+$/, '');
}

function safeHttpUrl(url: string | null): string | null {
    return url && /^https?:\/\//i.test(url) ? url : null;
}

export default function CompaniesIndexPage({ companies, filters, options }: Props) {
    const [local, setLocal] = useState<CompanyFilters>(filters);
    const [selectedIds, setSelectedIds] = useState<number[]>([]);
    const isFirstRender = useRef(true);
    const isTableLayout = useIsTableLayout();

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
        // Clearing filters keeps the chosen sort order.
        setLocal((prev) => ({ ...EMPTY_FILTERS, sort: prev.sort }));
    }

    const hasAnyFilter = hasActiveFilters(local);

    const rowProps = (company: CompanyRow, index: number) => ({
        ref: registerItem(index),
        tabIndex: 0,
        'aria-label': `${company.name}, tier ${company.tier}, ${company.open_roles_count} open roles`,
        onFocus: () => setFocusIndex(index),
        'data-state': selectedIds.includes(company.id) ? 'selected' : undefined,
    });

    return (
        <AuthenticatedLayout>
            <Head title="Companies" />
            <div className="flex flex-col gap-6">
                <header className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                    <h1 className="flex items-baseline gap-2 text-2xl font-semibold tracking-tight text-balance">
                        Companies
                        <span className="text-base font-normal tabular-nums text-muted-foreground">{companies.total}</span>
                    </h1>
                    <div className="flex items-center gap-2">
                        <SaveViewButton
                            entity="companies"
                            filters={local as unknown as Record<string, FormDataConvertible>}
                            disabled={!hasAnyFilter}
                        />
                        <Button asChild variant="outline" className="hidden sm:inline-flex">
                            <Link href="/import">Bulk import</Link>
                        </Button>
                        <Button asChild>
                            <Link href="/companies/create">Add company</Link>
                        </Button>
                        <div className="sm:hidden">
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <Button variant="outline" size="icon" aria-label="More actions">
                                        <MoreHorizontal aria-hidden="true" />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    <DropdownMenuItem asChild>
                                        <Link href="/import">Bulk import</Link>
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </div>
                    </div>
                </header>

                <CompanyFilterBar filters={local} options={options} onChange={patchFilters} onClear={clearFilters} />

                <div className="flex min-w-0 flex-col gap-4">
                    {companies.data.length === 0 ? (
                        <div className="py-12 text-center text-base text-pretty text-muted-foreground sm:text-sm">
                            No matching companies. Try clearing some filters.
                        </div>
                    ) : isTableLayout ? (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="w-10">
                                        <input
                                            type="checkbox"
                                            name="select_all"
                                            className="size-4 rounded border-input"
                                            aria-label="Select all companies on this page"
                                            checked={allOnPageSelected}
                                            onChange={toggleAllOnPage}
                                        />
                                    </TableHead>
                                    <TableHead>Company</TableHead>
                                    <TableHead>Tier</TableHead>
                                    <TableHead>Hiring 90d</TableHead>
                                    <TableHead className="text-right">Open</TableHead>
                                    <TableHead className="text-right">New 7d</TableHead>
                                    <TableHead className="text-right">Quality</TableHead>
                                    <TableHead>Signals</TableHead>
                                    <TableHead className="w-10">
                                        <span className="sr-only">Actions</span>
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {companies.data.map((company, index) => (
                                    <TableRow
                                        key={company.id}
                                        {...rowProps(company, index)}
                                        className={cn(
                                            'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring',
                                            isFocused(index) && 'bg-accent/50',
                                        )}
                                    >
                                        <TableCell>
                                            <RowCheckbox company={company} checked={selectedIds.includes(company.id)} onToggle={toggleRow} />
                                        </TableCell>
                                        <TableCell>
                                            <CompanyName company={company} />
                                        </TableCell>
                                        <TableCell>
                                            <TierBadge tier={company.tier} />
                                        </TableCell>
                                        <TableCell>
                                            <Sparkline values={company.hiring_trend} label="Open roles over 90 days" />
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">{company.open_roles_count}</TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            <NewRoles count={company.new_roles_7d} />
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {company.quality_score ?? <span className="text-muted-foreground">-</span>}
                                        </TableCell>
                                        <TableCell>
                                            <CompanySignals company={company} />
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <RowMenu company={company} />
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    ) : (
                        <ul className="flex flex-col divide-y divide-border border-y">
                            {companies.data.map((company, index) => (
                                <li
                                    key={company.id}
                                    {...rowProps(company, index)}
                                    className={cn(
                                        'flex flex-col gap-2 py-3 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring data-[state=selected]:bg-muted',
                                        isFocused(index) && 'bg-accent/50',
                                    )}
                                >
                                    <div className="flex items-start gap-3">
                                        <RowCheckbox company={company} checked={selectedIds.includes(company.id)} onToggle={toggleRow} />
                                        <div className="min-w-0 flex-1">
                                            <CompanyName company={company} />
                                        </div>
                                        <TierBadge tier={company.tier} />
                                        <RowMenu company={company} />
                                    </div>
                                    <div className="flex items-center gap-4 pl-7 text-base whitespace-nowrap tabular-nums">
                                        <span>
                                            <span className="text-muted-foreground">Open </span>
                                            {company.open_roles_count}
                                        </span>
                                        <span>
                                            <span className="text-muted-foreground">New </span>
                                            <NewRoles count={company.new_roles_7d} />
                                        </span>
                                        <span>
                                            <span className="text-muted-foreground">Quality </span>
                                            {company.quality_score ?? '-'}
                                        </span>
                                    </div>
                                    <div className="flex items-center gap-3 pl-7">
                                        <Sparkline values={company.hiring_trend} label="Open roles over 90 days" className="w-20" />
                                        {hasSignals(company) ? <CompanySignals company={company} /> : null}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}

                    {companies.last_page > 1 ? <Pagination links={companies.links} /> : null}
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

function hasSignals(company: CompanyRow): boolean {
    return computeSignals(company).length > 0;
}

function RowCheckbox({ company, checked, onToggle }: { company: CompanyRow; checked: boolean; onToggle: (id: number) => void }) {
    return (
        <input
            type="checkbox"
            name="selected_ids[]"
            className="mt-1 size-4 rounded border-input sm:mt-0"
            aria-label={`Select ${company.name}`}
            checked={checked}
            onChange={() => onToggle(company.id)}
        />
    );
}

function CompanyName({ company }: { company: CompanyRow }) {
    return (
        <div className="flex min-w-0 flex-col">
            <Link href={`/companies/${company.id}`} className="truncate font-medium text-foreground hover:underline">
                {company.name}
            </Link>
            {company.domain ? <span className="truncate text-sm text-muted-foreground sm:text-xs">{bareDomain(company.domain)}</span> : null}
        </div>
    );
}

function TierBadge({ tier }: { tier: CompanyRow['tier'] }) {
    return <Badge variant={TIER_VARIANT[tier] ?? 'outline'}>{tier === 'graveyard' ? tierLabel(tier) : tier}</Badge>;
}

function NewRoles({ count }: { count: number }) {
    return count > 0 ? <span>+{count}</span> : <span className="text-muted-foreground">-</span>;
}

function RowMenu({ company }: { company: CompanyRow }) {
    const careersUrl = safeHttpUrl(company.careers_url);

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="icon" aria-label={`Actions for ${company.name}`}>
                    <MoreHorizontal aria-hidden="true" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                <DropdownMenuItem asChild>
                    <Link href={`/companies/${company.id}/edit`}>Edit</Link>
                </DropdownMenuItem>
                {careersUrl ? (
                    <DropdownMenuItem asChild>
                        <a href={careersUrl} target="_blank" rel="noopener noreferrer">
                            Open careers page
                        </a>
                    </DropdownMenuItem>
                ) : null}
            </DropdownMenuContent>
        </DropdownMenu>
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
                            className="px-2 py-1 text-sm tabular-nums text-muted-foreground"
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
                            'rounded-md border px-3 py-1 text-sm tabular-nums',
                            link.active
                                ? 'border-border bg-muted font-medium text-foreground'
                                : 'border-transparent text-muted-foreground hover:bg-muted hover:text-foreground',
                        )}
                        dangerouslySetInnerHTML={{ __html: label }}
                    />
                );
            })}
        </nav>
    );
}
