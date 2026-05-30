import type { FormEventHandler } from 'react';

import { Head, useForm } from '@inertiajs/react';

import SettingsLayout from '@/Layouts/SettingsLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

interface NotificationSettings {
    digest_send_time: string;
    dnd_start: string;
    dnd_end: string;
    digest_skip_empty: boolean;
    notification_email: string;
    weekly_summary_enabled: boolean;
}

interface Props {
    settings: NotificationSettings;
}

export default function NotificationsPage({ settings }: Props) {
    const { data, setData, post, processing, errors, recentlySuccessful } =
        useForm<NotificationSettings>({
            digest_send_time: settings.digest_send_time,
            dnd_start: settings.dnd_start,
            dnd_end: settings.dnd_end,
            digest_skip_empty: settings.digest_skip_empty,
            notification_email: settings.notification_email,
            weekly_summary_enabled: settings.weekly_summary_enabled,
        });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        post('/settings/notifications', { preserveScroll: true });
    };

    return (
        <>
            <Head title="Notification settings" />
            <SettingsLayout
                title="Notifications"
                description="Control when and where digests and instant alerts are delivered."
            >
                <form
                    onSubmit={submit}
                    className="max-w-lg space-y-6 rounded-2xl border border-border bg-card p-6 text-card-foreground shadow-sm"
                >
                    <div className="space-y-2">
                        <Label htmlFor="notification_email">Notification email</Label>
                        <Input
                            id="notification_email"
                            type="email"
                            value={data.notification_email}
                            onChange={(event) => setData('notification_email', event.target.value)}
                            autoComplete="email"
                            required
                        />
                        <p className="text-xs text-muted-foreground">
                            Where digests, instant alerts, and the weekly summary are sent.
                        </p>
                        {errors.notification_email && (
                            <p className="text-sm text-destructive">{errors.notification_email}</p>
                        )}
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="digest_send_time">Daily digest time</Label>
                        <Input
                            id="digest_send_time"
                            type="time"
                            value={data.digest_send_time}
                            onChange={(event) => setData('digest_send_time', event.target.value)}
                            required
                        />
                        <p className="text-xs text-muted-foreground">
                            Wall-clock time in your timezone the daily digest is dispatched.
                        </p>
                        {errors.digest_send_time && (
                            <p className="text-sm text-destructive">{errors.digest_send_time}</p>
                        )}
                    </div>

                    <fieldset className="space-y-2">
                        <legend className="text-sm font-medium">Do-not-disturb window</legend>
                        <p className="text-xs text-muted-foreground">
                            Instant alerts that land in this window are deferred to the next allowed
                            minute, not dropped.
                        </p>
                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-2">
                                <Label htmlFor="dnd_start">From</Label>
                                <Input
                                    id="dnd_start"
                                    type="time"
                                    value={data.dnd_start}
                                    onChange={(event) => setData('dnd_start', event.target.value)}
                                    required
                                />
                                {errors.dnd_start && (
                                    <p className="text-sm text-destructive">{errors.dnd_start}</p>
                                )}
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="dnd_end">To</Label>
                                <Input
                                    id="dnd_end"
                                    type="time"
                                    value={data.dnd_end}
                                    onChange={(event) => setData('dnd_end', event.target.value)}
                                    required
                                />
                                {errors.dnd_end && (
                                    <p className="text-sm text-destructive">{errors.dnd_end}</p>
                                )}
                            </div>
                        </div>
                    </fieldset>

                    <label className="flex items-start gap-3 text-sm">
                        <input
                            type="checkbox"
                            checked={data.digest_skip_empty}
                            onChange={(event) => setData('digest_skip_empty', event.target.checked)}
                            className="mt-0.5 h-4 w-4 rounded border-input text-primary focus-visible:ring-ring"
                        />
                        <span>
                            <span className="font-medium text-foreground">Skip empty digests</span>
                            <span className="block text-muted-foreground">
                                Suppress the daily digest when nothing new happened.
                            </span>
                        </span>
                    </label>

                    <label className="flex items-start gap-3 text-sm">
                        <input
                            type="checkbox"
                            checked={data.weekly_summary_enabled}
                            onChange={(event) =>
                                setData('weekly_summary_enabled', event.target.checked)
                            }
                            className="mt-0.5 h-4 w-4 rounded border-input text-primary focus-visible:ring-ring"
                        />
                        <span>
                            <span className="font-medium text-foreground">Weekly summary</span>
                            <span className="block text-muted-foreground">
                                Send a weekly roundup of activity and stale A-tier companies.
                            </span>
                        </span>
                    </label>

                    <div className="flex items-center gap-3">
                        <Button type="submit" disabled={processing}>
                            Save
                        </Button>
                        {recentlySuccessful && (
                            <span className="text-sm text-muted-foreground">Saved.</span>
                        )}
                    </div>
                </form>
            </SettingsLayout>
        </>
    );
}
