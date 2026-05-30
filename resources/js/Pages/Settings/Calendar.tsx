import { useState } from 'react';
import type { FormEventHandler } from 'react';

import { Head, useForm } from '@inertiajs/react';

import SettingsLayout from '@/Layouts/SettingsLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

interface Props {
    feedUrl: string | null;
}

export default function CalendarPage({ feedUrl }: Props) {
    const { post, processing, recentlySuccessful } = useForm({});
    const [copied, setCopied] = useState(false);

    const regenerate: FormEventHandler = (event) => {
        event.preventDefault();
        post('/settings/calendar/regenerate', { preserveScroll: true });
    };

    const copyUrl = async () => {
        if (!feedUrl) {
            return;
        }

        try {
            await navigator.clipboard.writeText(feedUrl);
            setCopied(true);
            window.setTimeout(() => setCopied(false), 2000);
        } catch {
            setCopied(false);
        }
    };

    return (
        <>
            <Head title="Calendar feed" />
            <SettingsLayout
                title="Calendar feed"
                description="Subscribe to your interviews, deadlines and reminders from any calendar app. This is a private, read-only feed — anyone with the URL can see your events, so keep it secret."
            >
                <div className="max-w-lg space-y-6 rounded-2xl border border-border bg-card p-6 text-card-foreground shadow-sm">
                    <div className="space-y-2">
                        <Label htmlFor="feed-url">Subscription URL</Label>
                        {feedUrl ? (
                            <div className="flex items-center gap-2">
                                <Input
                                    id="feed-url"
                                    value={feedUrl}
                                    readOnly
                                    onFocus={(event) => event.target.select()}
                                    className="font-mono text-xs"
                                />
                                <Button
                                    type="button"
                                    variant="secondary"
                                    onClick={copyUrl}
                                >
                                    {copied ? 'Copied' : 'Copy'}
                                </Button>
                            </div>
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                No feed URL yet. Generate one to start subscribing.
                            </p>
                        )}
                        <p className="text-xs text-muted-foreground">
                            Add this URL to Google Calendar, Apple Calendar or Outlook
                            as a subscribed calendar. It refreshes automatically.
                        </p>
                    </div>

                    <form onSubmit={regenerate} className="space-y-3 border-t border-border pt-6">
                        <h3 className="text-sm font-medium">
                            {feedUrl ? 'Regenerate URL' : 'Generate URL'}
                        </h3>
                        <p className="text-sm text-muted-foreground">
                            {feedUrl
                                ? 'Regenerating immediately disables the current URL. Any calendar app subscribed to the old URL will stop updating until you re-subscribe.'
                                : 'Create a private subscription URL for your calendar app.'}
                        </p>
                        <div className="flex items-center gap-3">
                            <Button
                                type="submit"
                                variant={feedUrl ? 'destructive' : 'default'}
                                disabled={processing}
                            >
                                {feedUrl ? 'Regenerate URL' : 'Generate URL'}
                            </Button>
                            {recentlySuccessful && (
                                <span className="text-sm text-muted-foreground">Done.</span>
                            )}
                        </div>
                    </form>
                </div>
            </SettingsLayout>
        </>
    );
}
