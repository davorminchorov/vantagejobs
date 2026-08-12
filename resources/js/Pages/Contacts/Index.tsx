import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import ContactForm, { type ContactCompanyOption } from '@/components/contacts/ContactForm';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
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

    return (
        <AuthenticatedLayout>
            <Head title="Contacts" />
            <div className="space-y-6">
                <header className="flex items-end justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">Contacts</h1>
                        <p className="text-sm text-muted-foreground">
                            {meta.total === 0
                                ? 'No contacts match the current filters.'
                                : `${meta.total} ${meta.total === 1 ? 'contact' : 'contacts'} in your network.`}
                        </p>
                    </div>
                    <Dialog open={createOpen} onOpenChange={setCreateOpen}>
                        <DialogTrigger asChild>
                            <Button>Add contact</Button>
                        </DialogTrigger>
                        <DialogContent className="max-h-[90vh] max-w-2xl overflow-y-auto">
                            <DialogHeader>
                                <DialogTitle>Add a contact</DialogTitle>
                                <DialogDescription>Track someone in your network. Everything is optional except the name.</DialogDescription>
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
                </header>

                <div className="grid gap-6 md:grid-cols-[18rem_1fr]">
                    <aside className="hidden md:block">
                        <FilterPanel local={local} options={options} patchFilters={patchFilters} onClear={clearFilters} hasAny={hasAnyFilter} />
                    </aside>

                    <div className="space-y-3">
                        <Card className="rounded-2xl border-border">
                            <CardContent className="p-0">
                                {contacts.data.length === 0 ? (
                                    <div className="px-6 py-12 text-center text-sm text-muted-foreground">
                                        No matching contacts. Try clearing some filters or add your first contact.
                                    </div>
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
                                                            {contact.needs_followup ? (
                                                                <Badge variant="outline" className="border-amber-500/50 text-amber-600 dark:text-amber-400">
                                                                    Follow up
                                                                </Badge>
                                                            ) : null}
                                                        </div>
                                                        {contact.email ? (
                                                            <span className="font-mono text-xs text-muted-foreground">{contact.email}</span>
                                                        ) : null}
                                                    </TableCell>
                                                    <TableCell>
                                                        <span className="text-sm">{contact.role_title ?? '—'}</span>
                                                    </TableCell>
                                                    <TableCell>
                                                        {contact.company_id && contact.company_name ? (
                                                            <Link href={`/companies/${contact.company_id}`} className="text-sm hover:underline">
                                                                {contact.company_name}
                                                            </Link>
                                                        ) : (
                                                            <span className="text-xs text-muted-foreground">—</span>
                                                        )}
                                                    </TableCell>
                                                    <TableCell>
                                                        {contact.last_touch ? (
                                                            <RelativeDate
                                                                iso={contact.last_touch}
                                                                className="font-mono text-xs tabular-nums text-muted-foreground"
                                                            />
                                                        ) : (
                                                            <span className="font-mono text-xs tabular-nums text-muted-foreground">
                                                                Never
                                                            </span>
                                                        )}
                                                    </TableCell>
                                                    <TableCell className="text-right">
                                                        <Link
                                                            href={`/contacts/${contact.id}`}
                                                            className="text-xs font-medium text-muted-foreground hover:text-foreground"
                                                        >
                                                            View
                                                        </Link>
                                                    </TableCell>
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>
                                )}
                            </CardContent>
                        </Card>

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
}

function FilterPanel({ local, options, patchFilters, onClear, hasAny }: FilterPanelProps) {
    return (
        <Card className="rounded-2xl border-border">
            <CardContent className="space-y-4 pt-6">
                <div className="flex items-center justify-between">
                    <span className="text-sm font-medium">Filters</span>
                    {hasAny ? (
                        <Button variant="ghost" size="sm" onClick={onClear}>
                            Clear
                        </Button>
                    ) : null}
                </div>

                <div className="space-y-1.5">
                    <label className="font-mono text-xs font-medium uppercase tracking-wide text-muted-foreground">Search</label>
                    <Input
                        type="search"
                        placeholder="Name, email, role…"
                        value={local.q ?? ''}
                        onChange={(e) => patchFilters({ q: e.target.value === '' ? null : e.target.value })}
                    />
                </div>

                <div className="flex flex-col gap-1.5">
                    <label className="font-mono text-xs font-medium uppercase tracking-wide text-muted-foreground">Company</label>
                    <select
                        value={local.company_id === null ? '' : String(local.company_id)}
                        onChange={(e) => patchFilters({ company_id: e.target.value === '' ? null : Number(e.target.value) })}
                        className={cn(
                            'h-9 rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm',
                            'focus-visible:ring-ring focus-visible:outline-none focus-visible:ring-2',
                        )}
                    >
                        <option value="">All companies</option>
                        {options.companies.map((company) => (
                            <option key={company.id} value={String(company.id)}>
                                {company.name}
                            </option>
                        ))}
                    </select>
                </div>

                <div className="flex flex-col gap-1.5">
                    <label className="font-mono text-xs font-medium uppercase tracking-wide text-muted-foreground">Last touch</label>
                    <select
                        value={local.last_touch ?? ''}
                        onChange={(e) => patchFilters({ last_touch: e.target.value === '' ? null : e.target.value })}
                        className={cn(
                            'h-9 rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm',
                            'focus-visible:ring-ring focus-visible:outline-none focus-visible:ring-2',
                        )}
                    >
                        <option value="">Any time</option>
                        {options.last_touch.map((value) => (
                            <option key={value} value={value}>
                                {LAST_TOUCH_LABELS[value] ?? value}
                            </option>
                        ))}
                    </select>
                </div>

                <div className="space-y-1.5">
                    <label className="font-mono text-xs font-medium uppercase tracking-wide text-muted-foreground">Role title contains</label>
                    <Input
                        type="search"
                        placeholder="Engineering Manager"
                        value={local.role_title ?? ''}
                        onChange={(e) => patchFilters({ role_title: e.target.value === '' ? null : e.target.value })}
                    />
                </div>
            </CardContent>
        </Card>
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
