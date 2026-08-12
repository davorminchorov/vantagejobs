import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/components/PageHeader';
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
                <PageHeader
                    title="Follow-ups"
                    subtitle={
                        contacts.length > 0
                            ? `${contacts.length} ${contacts.length === 1 ? 'contact is' : 'contacts are'} overdue for a nudge — no touch in over ${threshold_days} days while their company has an active application.`
                            : 'No contacts are overdue right now.'
                    }
                    actions={
                        <span className="text-sm text-muted-foreground">
                            <span className="font-mono tabular-nums">{contacts.length}</span> due
                        </span>
                    }
                />

                {contacts.length === 0 ? (
                    <div className="mt-6 rounded-2xl border border-dashed border-border px-6 py-16 text-center text-sm text-muted-foreground">
                        You are all caught up. Contacts surface here once their company has a role in the
                        Applied or Screen stage and you have not logged a touch in over {threshold_days} days.
                    </div>
                ) : (
                    <Card className="mt-6 rounded-2xl border-border">
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
