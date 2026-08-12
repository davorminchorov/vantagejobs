import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import ComposeFromTemplatePanel, { type TemplateOption } from '@/components/contacts/ComposeFromTemplatePanel';
import ContactForm, { type ContactCompanyOption } from '@/components/contacts/ContactForm';
import OutreachLogPanel, { type OutreachLogEntry } from '@/components/contacts/OutreachLogPanel';
import { formatDateTime } from '@/lib/dates';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';

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

    const companies = props.companies ?? (contact.company_id && contact.company_name ? [{ id: contact.company_id, name: contact.company_name }] : []);

    function destroy() {
        router.delete(`/contacts/${contact.id}`, { preserveScroll: true });
    }

    return (
        <AuthenticatedLayout>
            <Head title={contact.name} />
            <div className="space-y-6">
                <header className="flex items-start justify-between gap-4">
                    <div className="space-y-1">
                        <div className="flex items-center gap-2">
                            <Link href="/contacts" className="text-sm text-muted-foreground hover:text-foreground">
                                Contacts
                            </Link>
                            <span className="text-muted-foreground">/</span>
                            <h1 className="text-2xl font-semibold tracking-tight">{contact.name}</h1>
                            {contact.needs_followup ? (
                                <Badge variant="outline" className="border-amber-500/50 text-amber-600 dark:text-amber-400">
                                    Follow up
                                </Badge>
                            ) : null}
                        </div>
                        <p className="text-sm text-muted-foreground">
                            {contact.role_title ?? 'No role recorded'}
                            {contact.company_id && contact.company_name ? (
                                <>
                                    {' · '}
                                    <Link href={`/companies/${contact.company_id}`} className="hover:underline">
                                        {contact.company_name}
                                    </Link>
                                </>
                            ) : null}
                        </p>
                    </div>
                    <div className="flex items-center gap-2">
                        <Dialog open={editOpen} onOpenChange={setEditOpen}>
                            <DialogTrigger asChild>
                                <Button variant="outline">Edit</Button>
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
                                <Button variant="ghost" className="text-destructive hover:text-destructive">
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
                                    <Button variant="ghost" onClick={() => setDeleteOpen(false)}>
                                        Cancel
                                    </Button>
                                    <Button variant="destructive" onClick={destroy}>
                                        Delete contact
                                    </Button>
                                </DialogFooter>
                            </DialogContent>
                        </Dialog>
                    </div>
                </header>

                <div className="grid gap-6 lg:grid-cols-[1fr_20rem]">
                    <div className="space-y-6">
                        <Card className="rounded-2xl border-border">
                            <CardHeader>
                                <CardTitle className="text-sm font-medium">Details</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3 text-sm">
                                <DetailRow label="Email">
                                    {contact.email ? (
                                        <a href={`mailto:${contact.email}`} className="font-mono hover:underline">
                                            {contact.email}
                                        </a>
                                    ) : (
                                        <span className="text-muted-foreground">—</span>
                                    )}
                                </DetailRow>
                                <DetailRow label="LinkedIn">
                                    {contact.linkedin_url ? (
                                        <a
                                            href={contact.linkedin_url}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="text-teal-700 hover:underline dark:text-teal-400"
                                        >
                                            {contact.linkedin_url}
                                        </a>
                                    ) : (
                                        <span className="text-muted-foreground">—</span>
                                    )}
                                </DetailRow>
                                <DetailRow label="Last touch">
                                    <span className="font-mono tabular-nums text-muted-foreground">
                                        {contact.last_touch ? formatDateTime(contact.last_touch) : 'Never'}
                                    </span>
                                </DetailRow>
                                {contact.notes ? (
                                    <div className="border-t pt-3">
                                        <p className="whitespace-pre-wrap text-sm text-foreground">{contact.notes}</p>
                                    </div>
                                ) : null}
                            </CardContent>
                        </Card>

                        <OutreachLogPanel contactId={contact.id} entries={outreach} channels={channels} />
                    </div>

                    <aside>
                        <ComposeFromTemplatePanel contactId={contact.id} templates={templates} />
                    </aside>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

function DetailRow({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div className="flex items-baseline justify-between gap-4">
            <span className="font-mono text-xs uppercase tracking-wide text-muted-foreground">{label}</span>
            <span className="text-right">{children}</span>
        </div>
    );
}
