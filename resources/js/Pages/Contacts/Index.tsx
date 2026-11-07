import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/components/PageHeader';
import ContactForm, { type ContactCompanyOption } from '@/components/contacts/ContactForm';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { SectionLabel } from '@/components/ui/section-label';
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetTrigger } from '@/components/ui/sheet';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { NativeSelect } from '@/components/ui/native-select';
import { RelativeDate } from '@/lib/dates';
import { cn } from '@/lib/utils';

interface ContactRow {
    id: number;
    name: string;
    role_title: string | null;
    linkedin_url: string | null;
    email: string | null;
    notes: string | null;
    company_id: number | null;
    company_name: string | null;
    last_touch: string | null;
    needs_followup: boolean;
}

interface PaginatedContacts {
    data: ContactRow[];
    meta: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
        links: { url: string | null; label: string; active: boolean }[];
    };
}

export interface ContactFilters {
    company_id: number | null;
    role_title: string | null;
    last_touch: string | null;
    q: string | null;
}

interface Options {
    companies: ContactCompanyOption[];
    last_touch: string[];
}

interface Props {
    contacts: PaginatedContacts;
    filters: ContactFilters;
    options: Options;
}

const LAST_TOUCH_LABELS: Record<string, string> = {
    never: 'Never contacted',
    last_7d: 'Touched in last 7 days',
    last_30d: 'Touched in last 30 days',
    older_than_30d: 'Older than 30 days',
};

const EMPTY_FILTERS: ContactFilters = {
    company_id: null,
    role_title: null,
    last_touch: null,
    q: null,
};

function contactFiltersToQuery(filters: ContactFilters): Record<string, string> {
    const params: Record<string, string> = {};
    if (filters.company_id !== null) params.company_id = String(filters.company_id);
    if (filters.role_title) params.role_title = filters.role_title;
    if (filters.last_touch) params.last_touch = filters.last_touch;
    if (filters.q) params.q = filters.q;
    return params;
}

export default function ContactsIndexPage({ contacts, filters, options }: Props) {
    const [local, setLocal] = useState<ContactFilters>(filters);
    const [createOpen, setCreateOpen] = useState(false);
    const isFirstRender = useRef(true);

    useEffect(() => {
        setLocal(filters);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [JSON.stringify(filters)]);

    useEffect(() => {
        if (isFirstRender.current) {
            isFirstRender.current = false;
            return;
        }

        const handle = window.setTimeout(() => {
            router.get('/contacts', contactFiltersToQuery(local), {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            });
        }, 300);

        return () => window.clearTimeout(handle);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [JSON.stringify(local)]);

    function patchFilters(patch: Partial<ContactFilters>) {
        setLocal((prev) => ({ ...prev, ...patch }));
    }

    function clearFilters() {
        setLocal(EMPTY_FILTERS);
    }

    const hasAnyFilter = useMemo(
        () => local.company_id !== null || local.role_title !== null || local.last_touch !== null || local.q !== null,
        [local],
    );

    const meta = contacts.meta;

    const renderFilterPanel = (idPrefix: string, className?: string) => (
        <FilterPanel
            local={local}
            options={options}
            patchFilters={patchFilters}
            onClear={clearFilters}
            hasAny={hasAnyFilter}
            idPrefix={idPrefix}
            className={className}
        />
    );

    return (
        <AuthenticatedLayout>
            <Head title="Contacts" />
            <div className="flex flex-col gap-6">
                <PageHeader
                    title="Contacts"
                    subtitle={
                        meta.total === 0
                            ? 'No contacts match the current filters.'
                            : `${meta.total} ${meta.total === 1 ? 'contact' : 'contacts'} in your network.`
                    }
                    actions={
                        <Dialog open={createOpen} onOpenChange={setCreateOpen}>
                            <DialogTrigger asChild>
                                <Button type="button">Add contact</Button>
                            </DialogTrigger>
                            <DialogContent className="max-h-[90vh] max-w-2xl overflow-y-auto">
                                <DialogHeader>
                                    <DialogTitle>Add a contact</DialogTitle>
                                    <DialogDescription>
                                        Track someone in your network. Everything is optional except the name.
                                    </DialogDescription>
                                </DialogHeader>
                                <ContactForm
                                    mode="create"
                                    initial={{}}
                                    companies={options.companies}
                                    action="/contacts"
                                    method="post"
                                    onCancel={() => setCreateOpen(false)}
                                    onSuccess={() => setCreateOpen(false)}
                                />
                            </DialogContent>
                        </Dialog>
                    }
                />

                <div className="grid gap-6 lg:grid-cols-[16rem_minmax(0,1fr)]">
                    <aside className="hidden lg:block">{renderFilterPanel('contact-filter', 'border-r border-border pr-6')}</aside>

                    <div className="flex min-w-0 flex-col gap-4">
                        <div className="lg:hidden">
                            <Sheet>
                                <SheetTrigger asChild>
                                    <Button type="button" variant="outline" size="sm">
                                        Filters{hasAnyFilter ? ' (active)' : ''}
                                    </Button>
                                </SheetTrigger>
                                <SheetContent side="left" className="w-80 overflow-y-auto">
                                    <SheetHeader>
                                        <SheetTitle>Filters</SheetTitle>
                                    </SheetHeader>
                                    <div className="mt-4 px-1">{renderFilterPanel('contact-filter-sheet')}</div>
                                </SheetContent>
                            </Sheet>
                        </div>

                        {contacts.data.length === 0 ? (
                            <EmptyState>No matching contacts. Try clearing some filters or add your first contact.</EmptyState>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Name</TableHead>
                                        <TableHead>Role</TableHead>
                                        <TableHead>Company</TableHead>
                                        <TableHead>Last touch</TableHead>
                                        <TableHead className="text-right">Actions</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {contacts.data.map((contact) => (
                                        <TableRow key={contact.id}>
                                            <TableCell>
                                                <div className="flex items-center gap-2">
                                                    <Link href={`/contacts/${contact.id}`} className="font-medium hover:underline">
                                                        {contact.name}
                                                    </Link>
                                                    {contact.needs_followup ? <Badge variant="warning">Follow up</Badge> : null}
                                                </div>
                                                {contact.email ? (
                                                    <div className="text-xs text-muted-foreground">{contact.email}</div>
                                                ) : null}
                                            </TableCell>
                                            <TableCell>{contact.role_title ?? '—'}</TableCell>
                                            <TableCell>
                                                {contact.company_id && contact.company_name ? (
                                                    <Link href={`/companies/${contact.company_id}`} className="hover:underline">
                                                        {contact.company_name}
                                                    </Link>
                                                ) : (
                                                    <span className="text-muted-foreground">—</span>
                                                )}
                                            </TableCell>
                                            <TableCell className="tabular-nums text-muted-foreground">
                                                {contact.last_touch ? <RelativeDate iso={contact.last_touch} /> : 'Never'}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <Button asChild variant="outline" size="sm">
                                                    <Link href={`/contacts/${contact.id}`}>View</Link>
                                                </Button>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}

                        {meta.last_page > 1 ? <Pagination links={meta.links} /> : null}
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

interface FilterPanelProps {
    local: ContactFilters;
    options: Options;
    patchFilters: (patch: Partial<ContactFilters>) => void;
    onClear: () => void;
    hasAny: boolean;
    idPrefix: string;
    className?: string;
}

function FilterPanel({ local, options, patchFilters, onClear, hasAny, idPrefix, className }: FilterPanelProps) {
    return (
        <div className={cn('flex flex-col gap-4', className)}>
            <div className="flex items-center justify-between gap-2">
                <SectionLabel>Filters</SectionLabel>
                {hasAny ? (
                    <Button type="button" variant="ghost" size="sm" onClick={onClear}>
                        Clear
                    </Button>
                ) : null}
            </div>

            <div className="flex flex-col gap-1.5">
                <Label htmlFor={`${idPrefix}-q`}>Search</Label>
                <Input
                    id={`${idPrefix}-q`}
                    name="q"
                    type="search"
                    placeholder="Name, email, role…"
                    value={local.q ?? ''}
                    onChange={(e) => patchFilters({ q: e.target.value === '' ? null : e.target.value })}
                />
            </div>

            <div className="flex flex-col gap-1.5">
                <Label htmlFor={`${idPrefix}-company`}>Company</Label>
                <NativeSelect
                    id={`${idPrefix}-company`}
                    name="company_id"
                    value={local.company_id === null ? '' : String(local.company_id)}
                    onChange={(e) => patchFilters({ company_id: e.target.value === '' ? null : Number(e.target.value) })}
                >
                    <option value="">All companies</option>
                    {options.companies.map((company) => (
                        <option key={company.id} value={String(company.id)}>
                            {company.name}
                        </option>
                    ))}
                </NativeSelect>
            </div>

            <div className="flex flex-col gap-1.5">
                <Label htmlFor={`${idPrefix}-last-touch`}>Last touch</Label>
                <NativeSelect
                    id={`${idPrefix}-last-touch`}
                    name="last_touch"
                    value={local.last_touch ?? ''}
                    onChange={(e) => patchFilters({ last_touch: e.target.value === '' ? null : e.target.value })}
                >
                    <option value="">Any time</option>
                    {options.last_touch.map((value) => (
                        <option key={value} value={value}>
                            {LAST_TOUCH_LABELS[value] ?? value}
                        </option>
                    ))}
                </NativeSelect>
            </div>

            <div className="flex flex-col gap-1.5">
                <Label htmlFor={`${idPrefix}-role`}>Role title contains</Label>
                <Input
                    id={`${idPrefix}-role`}
                    name="role_title"
                    type="search"
                    placeholder="Engineering Manager"
                    value={local.role_title ?? ''}
                    onChange={(e) => patchFilters({ role_title: e.target.value === '' ? null : e.target.value })}
                />
            </div>
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
                            'rounded-md px-3 py-1 text-sm tabular-nums',
                            link.active
                                ? 'bg-muted font-medium text-foreground'
                                : 'text-muted-foreground hover:bg-muted hover:text-foreground',
                        )}
                        dangerouslySetInnerHTML={{ __html: label }}
                    />
                );
            })}
        </nav>
    );
}
