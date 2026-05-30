import { Link } from '@inertiajs/react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { TableCell, TableRow } from '@/components/ui/table';

export interface FollowUpContact {
    id: number;
    name: string;
    role_title: string | null;
    email: string | null;
    linkedin_url: string | null;
    company_id: number | null;
    company_name: string | null;
    last_touch: string | null;
    days_since_touch: number | null;
}

interface Props {
    contact: FollowUpContact;
    onLogTouch: (contact: FollowUpContact) => void;
    pending: boolean;
}

function overdueLabel(days: number | null): string {
    if (days === null) {
        return 'Never contacted';
    }
    if (days <= 0) {
        return 'Touched today';
    }
    return `${days} ${days === 1 ? 'day' : 'days'} ago`;
}

export default function FollowUpRow({ contact, onLogTouch, pending }: Props) {
    const [confirming, setConfirming] = useState(false);

    return (
        <TableRow>
            <TableCell>
                <div className="flex flex-col">
                    <Link href={`/contacts/${contact.id}`} className="font-medium hover:underline">
                        {contact.name}
                    </Link>
                    {contact.role_title ? (
                        <span className="text-xs text-muted-foreground">{contact.role_title}</span>
                    ) : null}
                </div>
            </TableCell>
            <TableCell>
                {contact.company_name ? (
                    contact.company_id !== null ? (
                        <Link
                            href={`/companies/${contact.company_id}`}
                            className="text-sm hover:underline"
                        >
                            {contact.company_name}
                        </Link>
                    ) : (
                        <span className="text-sm">{contact.company_name}</span>
                    )
                ) : (
                    <span className="text-xs text-muted-foreground">—</span>
                )}
            </TableCell>
            <TableCell>
                <Badge variant={contact.last_touch === null ? 'destructive' : 'outline'}>
                    {overdueLabel(contact.days_since_touch)}
                </Badge>
            </TableCell>
            <TableCell>
                <span className="font-mono text-xs tabular-nums text-muted-foreground">
                    {contact.last_touch ? new Date(contact.last_touch).toLocaleDateString() : 'Never'}
                </span>
            </TableCell>
            <TableCell className="text-right">
                {confirming ? (
                    <div className="flex items-center justify-end gap-1">
                        <Button
                            size="sm"
                            disabled={pending}
                            onClick={() => {
                                onLogTouch(contact);
                                setConfirming(false);
                            }}
                        >
                            {pending ? 'Logging…' : 'Confirm'}
                        </Button>
                        <Button size="sm" variant="ghost" onClick={() => setConfirming(false)} disabled={pending}>
                            Cancel
                        </Button>
                    </div>
                ) : (
                    <Button size="sm" variant="outline" onClick={() => setConfirming(true)} disabled={pending}>
                        Log touch
                    </Button>
                )}
            </TableCell>
        </TableRow>
    );
}
