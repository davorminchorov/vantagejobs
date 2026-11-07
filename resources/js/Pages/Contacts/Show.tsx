import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/components/PageHeader';
import ComposeFromTemplatePanel, { type TemplateOption } from '@/components/contacts/ComposeFromTemplatePanel';
import ContactForm, { type ContactCompanyOption } from '@/components/contacts/ContactForm';
import OutreachLogPanel, { type OutreachLogEntry } from '@/components/contacts/OutreachLogPanel';
import { formatDateTime } from '@/lib/dates';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';

interface Contact {
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

interface Props {
    contact: Contact;
    outreach: OutreachLogEntry[];
    templates: TemplateOption[];
    channels: string[];
}

export default function ContactShowPage({ contact, outreach, templates, channels }: Props) {
    const { props } = usePage<{ companies?: ContactCompanyOption[] }>();
    const [editOpen, setEditOpen] = useState(false);
    const [deleteOpen, setDeleteOpen] = useState(false);

    const companies =
        props.companies ?? (contact.company_id && contact.company_name ? [{ id: contact.company_id, name: contact.company_name }] : []);

    function destroy() {
        router.delete(`/contacts/${contact.id}`, { preserveScroll: true });
    }

    return (
        <AuthenticatedLayout>
            <Head title={contact.name} />
            <div className="flex flex-col gap-6">
                <div className="flex flex-col gap-2">
                    <Link href="/contacts" className="text-sm text-muted-foreground hover:text-foreground">
                        Contacts
                    </Link>
                    <PageHeader
                        title={contact.name}
                        subtitle={
                            <span className="flex flex-wrap items-center gap-x-2 gap-y-1">
                                <span>
                                    {contact.role_title ?? 'No role recorded'}
                                    {contact.company_id && contact.company_name ? (
                                        <>
                                            {' · '}
                                            <Link href={`/companies/${contact.company_id}`} className="hover:underline">
                                                {contact.company_name}
                                            </Link>
                                        </>
                                    ) : null}
                                </span>
                                {contact.needs_followup ? <Badge variant="warning">Follow up</Badge> : null}
                            </span>
                        }
                        actions={
                            <>
                                <Dialog open={editOpen} onOpenChange={setEditOpen}>
                                    <DialogTrigger asChild>
                                        <Button type="button" variant="outline">
                                            Edit
                                        </Button>
                                    </DialogTrigger>
                                    <DialogContent className="max-h-[90vh] max-w-2xl overflow-y-auto">
                                        <DialogHeader>
                                            <DialogTitle>Edit contact</DialogTitle>
                                            <DialogDescription>Update {contact.name}&apos;s details.</DialogDescription>
                                        </DialogHeader>
                                        <ContactForm
                                            mode="edit"
                                            initial={{
                                                name: contact.name,
                                                role_title: contact.role_title ?? '',
                                                linkedin_url: contact.linkedin_url ?? '',
                                                email: contact.email ?? '',
                                                notes: contact.notes ?? '',
                                                company_id: contact.company_id ? String(contact.company_id) : '',
                                            }}
                                            companies={companies}
                                            action={`/contacts/${contact.id}`}
                                            method="put"
                                            onCancel={() => setEditOpen(false)}
                                            onSuccess={() => setEditOpen(false)}
                                        />
                                    </DialogContent>
                                </Dialog>

                                <Dialog open={deleteOpen} onOpenChange={setDeleteOpen}>
                                    <DialogTrigger asChild>
                                        <Button type="button" variant="ghost" className="text-destructive hover:text-destructive">
                                            Delete
                                        </Button>
                                    </DialogTrigger>
                                    <DialogContent>
                                        <DialogHeader>
                                            <DialogTitle>Delete {contact.name}?</DialogTitle>
                                            <DialogDescription>
                                                This removes the contact and their outreach history. This cannot be undone.
                                            </DialogDescription>
                                        </DialogHeader>
                                        <DialogFooter>
                                            <Button type="button" variant="ghost" onClick={() => setDeleteOpen(false)}>
                                                Cancel
                                            </Button>
                                            <Button type="button" variant="destructive" onClick={destroy}>
                                                Delete contact
                                            </Button>
                                        </DialogFooter>
                                    </DialogContent>
                                </Dialog>
                            </>
                        }
                    />
                </div>

                <div className="grid gap-8 lg:grid-cols-[minmax(0,1fr)_22rem]">
                    <div className="flex min-w-0 flex-col gap-8">
                        <section className="flex flex-col gap-4">
                            <h2 className="text-base font-semibold text-balance">Details</h2>
                            <dl className="flex flex-col gap-3 text-base sm:text-sm">
                                <DetailRow label="Email">
                                    {contact.email ? (
                                        <a href={`mailto:${contact.email}`} className="break-all hover:underline">
                                            {contact.email}
                                        </a>
                                    ) : (
                                        '—'
                                    )}
                                </DetailRow>
                                <DetailRow label="LinkedIn">
                                    {contact.linkedin_url ? (
                                        <a
                                            href={contact.linkedin_url}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="break-all text-primary hover:underline"
                                        >
                                            {contact.linkedin_url}
                                        </a>
                                    ) : (
                                        '—'
                                    )}
                                </DetailRow>
                                <DetailRow label="Last touch">
                                    <span className="tabular-nums">
                                        {contact.last_touch ? formatDateTime(contact.last_touch) : 'Never'}
                                    </span>
                                </DetailRow>
                            </dl>
                            {contact.notes ? (
                                <p className="border-t border-border pt-4 text-base whitespace-pre-wrap text-pretty text-foreground sm:text-sm">
                                    {contact.notes}
                                </p>
                            ) : null}
                        </section>

                        <OutreachLogPanel contactId={contact.id} entries={outreach} channels={channels} />
                    </div>

                    <aside className="min-w-0">
                        <ComposeFromTemplatePanel contactId={contact.id} templates={templates} />
                    </aside>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

function DetailRow({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div className="flex flex-col gap-0.5 sm:grid sm:grid-cols-[10rem_minmax(0,1fr)] sm:items-baseline">
            <dt className="font-medium text-foreground">{label}</dt>
            <dd className="text-muted-foreground">{children}</dd>
        </div>
    );
}
