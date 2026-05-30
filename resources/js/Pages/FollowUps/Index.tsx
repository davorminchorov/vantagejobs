import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import FollowUpRow, { type FollowUpContact } from '@/components/followups/FollowUpRow';
import { Card, CardContent } from '@/components/ui/card';
import { Table, TableBody, TableHead, TableHeader, TableRow } from '@/components/ui/table';

interface Props {
    contacts: FollowUpContact[];
    threshold_days: number;
}

export default function FollowUpsIndexPage({ contacts, threshold_days }: Props) {
    const [pendingId, setPendingId] = useState<number | null>(null);

    function logTouch(contact: FollowUpContact) {
        setPendingId(contact.id);
        router.post(
            `/follow-ups/${contact.id}/touch`,
            {},
            {
                preserveScroll: true,
                onFinish: () => setPendingId(null),
            },
        );
    }

    return (
        <AuthenticatedLayout>
            <Head title="Follow-ups" />

            <div className="text-foreground">
                <header className="mb-6 flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <h1 className="bg-gradient-to-r from-teal-600 to-emerald-500 bg-clip-text text-3xl font-semibold tracking-tight text-transparent dark:from-teal-400 dark:to-emerald-300">
                            Follow-ups
                        </h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {contacts.length > 0
                                ? `${contacts.length} ${contacts.length === 1 ? 'contact is' : 'contacts are'} overdue for a nudge — no touch in over ${threshold_days} days while their company has an active application.`
                                : 'No contacts are overdue right now.'}
                        </p>
                    </div>
                    <span className="rounded-full bg-teal-600 px-4 py-1.5 text-sm font-semibold text-white shadow-lg shadow-teal-600/25">
                        <span className="font-mono tabular-nums">{contacts.length}</span> due
                    </span>
                </header>

                {contacts.length === 0 ? (
                    <div className="rounded-2xl border border-dashed border-border px-6 py-16 text-center text-sm text-muted-foreground">
                        You are all caught up. Contacts surface here once their company has a role in the
                        Applied or Screen stage and you have not logged a touch in over {threshold_days} days.
                    </div>
                ) : (
                    <Card className="rounded-2xl border-border">
                        <CardContent className="p-0">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Contact</TableHead>
                                        <TableHead>Company</TableHead>
                                        <TableHead>Overdue</TableHead>
                                        <TableHead>Last touch</TableHead>
                                        <TableHead className="text-right">Action</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {contacts.map((contact) => (
                                        <FollowUpRow
                                            key={contact.id}
                                            contact={contact}
                                            onLogTouch={logTouch}
                                            pending={pendingId === contact.id}
                                        />
                                    ))}
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
