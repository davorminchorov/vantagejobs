import { useForm } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { formatDateTime } from '@/lib/dates';

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
        <section className="flex flex-col gap-6">
            <h2 className="text-base font-semibold text-balance">Outreach log</h2>
            <form onSubmit={submit} className="flex max-w-2xl flex-col gap-4">
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field id="outreach-channel" label="Channel" error={form.errors.channel}>
                        <NativeSelect
                            id="outreach-channel"
                            name="channel"
                            value={form.data.channel}
                            onChange={(e) => form.setData('channel', e.target.value)}
                        >
                            {channels.map((channel) => (
                                <option key={channel} value={channel}>
                                    {humanize(channel)}
                                </option>
                            ))}
                        </NativeSelect>
                    </Field>

                    <Field id="outreach-occurred-at" label="When" error={form.errors.occurred_at}>
                        <Input
                            id="outreach-occurred-at"
                            name="occurred_at"
                            type="date"
                            value={form.data.occurred_at}
                            onChange={(e) => form.setData('occurred_at', e.target.value)}
                        />
                    </Field>
                </div>

                <Field id="outreach-subject" label="Subject" error={form.errors.subject}>
                    <Input
                        id="outreach-subject"
                        name="subject"
                        value={form.data.subject}
                        onChange={(e) => form.setData('subject', e.target.value)}
                        placeholder="e.g. Intro on the platform role"
                    />
                </Field>

                <Field id="outreach-body" label="Notes" error={form.errors.body}>
                    <textarea
                        id="outreach-body"
                        name="body"
                        rows={4}
                        className="w-full rounded-md border border-input bg-background px-3 py-2 text-base placeholder:text-muted-foreground focus-visible:outline-2 focus-visible:-outline-offset-1 focus-visible:outline-ring sm:text-sm"
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
                        <span className="text-base text-emerald-700 sm:text-sm dark:text-emerald-400">Logged.</span>
                    ) : null}
                </div>
            </form>

            <div className="border-t border-border pt-6">
                {entries.length === 0 ? (
                    <p className="text-base text-muted-foreground sm:text-sm">No outreach logged yet.</p>
                ) : (
                    <ol role="list" className="flex flex-col divide-y divide-border">
                        {entries.map((entry) => (
                            <li key={entry.id} className="flex flex-col gap-1 py-4 first:pt-0 last:pb-0">
                                <div className="flex items-center justify-between gap-2">
                                    <Badge variant="default">{humanize(entry.channel)}</Badge>
                                    <span className="text-xs tabular-nums text-muted-foreground">
                                        {formatDateTime(entry.occurred_at)}
                                    </span>
                                </div>
                                {entry.subject ? (
                                    <p className="text-base font-medium text-foreground sm:text-sm">{entry.subject}</p>
                                ) : null}
                                {entry.body ? (
                                    <p className="text-base whitespace-pre-line text-pretty text-muted-foreground sm:text-sm">{entry.body}</p>
                                ) : null}
                            </li>
                        ))}
                    </ol>
                )}
            </div>
        </section>
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
            {error ? <span className="text-base text-destructive sm:text-sm">{error}</span> : null}
        </div>
    );
}
