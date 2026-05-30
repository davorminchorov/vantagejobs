import { useForm } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';

export interface OutreachLogEntry {
    id: number;
    channel: string;
    subject: string | null;
    body: string | null;
    occurred_at: string | null;
}

interface OutreachLogFormValues {
    channel: string;
    subject: string;
    body: string;
    occurred_at: string;
}

interface OutreachLogPanelProps {
    contactId: number;
    entries: OutreachLogEntry[];
    channels: string[];
}

function humanize(value: string): string {
    return value.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

function formatTimestamp(value: string | null): string {
    if (!value) {
        return '';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return date.toLocaleString(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
}

export default function OutreachLogPanel({ contactId, entries, channels }: OutreachLogPanelProps) {
    const form = useForm<OutreachLogFormValues>({
        channel: channels[0] ?? 'email',
        subject: '',
        body: '',
        occurred_at: '',
    });

    function submit(e: React.FormEvent) {
        e.preventDefault();
        form.post(`/contacts/${contactId}/outreach`, {
            preserveScroll: true,
            onSuccess: () => form.reset('subject', 'body', 'occurred_at'),
        });
    }

    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-base">Outreach log</CardTitle>
            </CardHeader>
            <CardContent className="space-y-6">
                <form onSubmit={submit} className="space-y-4">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field id="outreach-channel" label="Channel" error={form.errors.channel}>
                            <select
                                id="outreach-channel"
                                value={form.data.channel}
                                onChange={(e) => form.setData('channel', e.target.value)}
                                className={cn(
                                    'flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm',
                                    'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                                )}
                            >
                                {channels.map((channel) => (
                                    <option key={channel} value={channel}>
                                        {humanize(channel)}
                                    </option>
                                ))}
                            </select>
                        </Field>

                        <Field id="outreach-occurred-at" label="When" error={form.errors.occurred_at}>
                            <Input
                                id="outreach-occurred-at"
                                type="date"
                                value={form.data.occurred_at}
                                onChange={(e) => form.setData('occurred_at', e.target.value)}
                            />
                        </Field>
                    </div>

                    <Field id="outreach-subject" label="Subject" error={form.errors.subject}>
                        <Input
                            id="outreach-subject"
                            value={form.data.subject}
                            onChange={(e) => form.setData('subject', e.target.value)}
                            placeholder="e.g. Intro on the platform role"
                        />
                    </Field>

                    <Field id="outreach-body" label="Notes" error={form.errors.body}>
                        <textarea
                            id="outreach-body"
                            className="min-h-24 w-full rounded-md border border-input bg-background p-2 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            value={form.data.body}
                            onChange={(e) => form.setData('body', e.target.value)}
                            placeholder="What you said, what you heard back."
                        />
                    </Field>

                    <div className="flex items-center gap-3">
                        <Button type="submit" disabled={form.processing}>
                            {form.processing ? 'Logging…' : 'Log outreach'}
                        </Button>
                        {form.recentlySuccessful ? (
                            <span className="text-sm text-teal-700 dark:text-teal-400">Logged.</span>
                        ) : null}
                    </div>
                </form>

                <div className="border-t border-border pt-4">
                    {entries.length === 0 ? (
                        <p className="text-sm text-muted-foreground">No outreach logged yet.</p>
                    ) : (
                        <ol className="space-y-4">
                            {entries.map((entry) => (
                                <li key={entry.id} className="flex flex-col gap-1">
                                    <div className="flex items-center justify-between gap-2">
                                        <span className="inline-flex items-center rounded-full bg-teal-50 px-2 py-0.5 text-xs font-medium text-teal-700 dark:bg-teal-950 dark:text-teal-300">
                                            {humanize(entry.channel)}
                                        </span>
                                        <span className="text-xs text-muted-foreground">
                                            {formatTimestamp(entry.occurred_at)}
                                        </span>
                                    </div>
                                    {entry.subject ? (
                                        <p className="text-sm font-medium text-foreground">{entry.subject}</p>
                                    ) : null}
                                    {entry.body ? (
                                        <p className="whitespace-pre-line text-sm text-muted-foreground">{entry.body}</p>
                                    ) : null}
                                </li>
                            ))}
                        </ol>
                    )}
                </div>
            </CardContent>
        </Card>
    );
}

interface FieldProps {
    id: string;
    label: string;
    error?: string;
    children: React.ReactNode;
}

function Field({ id, label, error, children }: FieldProps) {
    return (
        <div className="flex flex-col gap-1.5">
            <Label htmlFor={id}>{label}</Label>
            {children}
            {error ? <span className="text-sm text-destructive">{error}</span> : null}
        </div>
    );
}
