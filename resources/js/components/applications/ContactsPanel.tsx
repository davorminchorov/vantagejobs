import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { SectionLabel } from '@/components/ui/section-label';
import { RelativeDate } from '@/lib/dates';

import { ComposeInline } from './ComposeInline';
import type { ApplicationContact, TemplateOption } from './types';

interface ContactsPanelProps {
    company: { id: number; name: string } | null;
    contacts: ApplicationContact[];
    templates: TemplateOption[];
}

export function ContactsPanel({ company, contacts, templates }: ContactsPanelProps) {
    const [composeFor, setComposeFor] = useState<number | null>(null);
    const [pendingTouch, setPendingTouch] = useState<number | null>(null);
    const [adding, setAdding] = useState(false);

    function logTouch(contact: ApplicationContact) {
        setPendingTouch(contact.id);
        router.post(
            `/follow-ups/${contact.id}/touch`,
            {},
            {
                preserveScroll: true,
                preserveState: true,
                onFinish: () => setPendingTouch(null),
            },
        );
    }

    return (
        <section className="flex flex-col gap-3">
            <div className="flex items-center justify-between gap-2">
                <SectionLabel>{company ? `Contacts at ${company.name}` : 'Contacts'}</SectionLabel>
                {company && !adding ? (
                    <Button type="button" variant="outline" size="sm" onClick={() => setAdding(true)}>
                        Add contact
                    </Button>
                ) : null}
            </div>

            {contacts.length === 0 && !adding ? (
                <p className="text-base text-pretty text-muted-foreground sm:text-sm">No contacts at this company yet.</p>
            ) : (
                <ul role="list" className="divide-y divide-border border-y border-border">
                    {contacts.map((contact) => (
                        <li key={contact.id} className="flex flex-col gap-3 py-3">
                            <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
                                <div className="flex min-w-0 flex-col">
                                    <Link
                                        href={`/contacts/${contact.id}`}
                                        className="truncate text-base font-medium text-primary hover:underline sm:text-sm"
                                    >
                                        {contact.name}
                                    </Link>
                                    {contact.role_title ? (
                                        <span className="truncate text-sm text-muted-foreground">{contact.role_title}</span>
                                    ) : null}
                                    <span className="text-sm text-muted-foreground">
                                        {contact.last_touch ? (
                                            <>
                                                Last touch <RelativeDate iso={contact.last_touch} />
                                            </>
                                        ) : (
                                            'Never contacted'
                                        )}
                                    </span>
                                </div>
                                <div className="flex shrink-0 items-center gap-2">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={() => logTouch(contact)}
                                        disabled={pendingTouch === contact.id}
                                    >
                                        Log touch
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        aria-expanded={composeFor === contact.id}
                                        onClick={() => setComposeFor((current) => (current === contact.id ? null : contact.id))}
                                    >
                                        Compose
                                    </Button>
                                </div>
                            </div>
                            {composeFor === contact.id ? <ComposeInline contactId={contact.id} templates={templates} /> : null}
                        </li>
                    ))}
                </ul>
            )}

            {company && adding ? <AddContactForm companyId={company.id} onDone={() => setAdding(false)} /> : null}
        </section>
    );
}

function AddContactForm({ companyId, onDone }: { companyId: number; onDone: () => void }) {
    const form = useForm({ name: '', company_id: companyId, role_title: '', email: '', linkedin_url: '', return_to: 'applications' });

    function submit(e: React.FormEvent) {
        e.preventDefault();
        form.post('/contacts', {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => onDone(),
        });
    }

    return (
        <form onSubmit={submit} className="flex flex-col gap-3 rounded-lg border border-border p-4">
            <div className="grid gap-3 sm:grid-cols-2">
                <div className="flex flex-col gap-1.5">
                    <Label htmlFor="new-contact-name">Name</Label>
                    <Input id="new-contact-name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} autoFocus />
                    {form.errors.name ? <span className="text-sm text-destructive">{form.errors.name}</span> : null}
                </div>
                <div className="flex flex-col gap-1.5">
                    <Label htmlFor="new-contact-title">Title</Label>
                    <Input
                        id="new-contact-title"
                        value={form.data.role_title}
                        onChange={(e) => form.setData('role_title', e.target.value)}
                    />
                    {form.errors.role_title ? <span className="text-sm text-destructive">{form.errors.role_title}</span> : null}
                </div>
                <div className="flex flex-col gap-1.5">
                    <Label htmlFor="new-contact-email">Email</Label>
                    <Input
                        id="new-contact-email"
                        type="email"
                        value={form.data.email}
                        onChange={(e) => form.setData('email', e.target.value)}
                    />
                    {form.errors.email ? <span className="text-sm text-destructive">{form.errors.email}</span> : null}
                </div>
                <div className="flex flex-col gap-1.5">
                    <Label htmlFor="new-contact-linkedin">LinkedIn URL</Label>
                    <Input
                        id="new-contact-linkedin"
                        type="url"
                        value={form.data.linkedin_url}
                        onChange={(e) => form.setData('linkedin_url', e.target.value)}
                    />
                    {form.errors.linkedin_url ? <span className="text-sm text-destructive">{form.errors.linkedin_url}</span> : null}
                </div>
            </div>
            <div className="flex items-center gap-3">
                <Button type="submit" disabled={form.processing || form.data.name.trim() === ''}>
                    {form.processing ? 'Saving…' : 'Save contact'}
                </Button>
                <Button type="button" variant="ghost" onClick={onDone}>
                    Cancel
                </Button>
            </div>
        </form>
    );
}
