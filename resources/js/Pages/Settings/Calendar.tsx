import { useState } from 'react';
import type { FormEventHandler } from 'react';

import { Head, useForm } from '@inertiajs/react';

import SettingsLayout, { SettingsSection } from '@/Layouts/SettingsLayout';
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
                <SettingsSection
                    title="Subscription URL"
                    description="Add this URL to Google Calendar, Apple Calendar or Outlook as a subscribed calendar. It refreshes automatically."
                >
                    <div className="flex flex-col gap-2">
                        <Label htmlFor="feed-url">Subscription URL</Label>
                        {feedUrl ? (
                            <div className="flex items-center gap-2">
                                <Input
                                    id="feed-url"
                                    name="feed_url"
                                    value={feedUrl}
                                    readOnly
                                    onFocus={(event) => event.target.select()}
                                    className="min-w-0 font-mono text-xs"
                                />
                                <Button type="button" variant="secondary" size="sm" onClick={copyUrl}>
                                    {copied ? 'Copied' : 'Copy'}
                                </Button>
                            </div>
                        ) : (
                            <p className="text-base text-pretty text-muted-foreground sm:text-sm">
                                No feed URL yet. Generate one to start subscribing.
                            </p>
                        )}
                    </div>
                </SettingsSection>

                <SettingsSection
                    title={feedUrl ? 'Regenerate URL' : 'Generate URL'}
                    description={
                        feedUrl
                            ? 'Regenerating immediately disables the current URL. Any calendar app subscribed to the old URL will stop updating until you re-subscribe.'
                            : 'Create a private subscription URL for your calendar app.'
                    }
                >
                    <form onSubmit={regenerate} className="flex items-center gap-3">
                        <Button type="submit" variant={feedUrl ? 'outline' : 'default'} disabled={processing}>
                            {feedUrl ? 'Regenerate URL' : 'Generate URL'}
                        </Button>
                        {recentlySuccessful && <span className="text-sm text-muted-foreground">Done.</span>}
                    </form>
                </SettingsSection>
            </SettingsLayout>
        </>
    );
}
