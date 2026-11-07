import type { FormEventHandler } from 'react';

import { Head, useForm } from '@inertiajs/react';

import SettingsLayout, { SettingsSection } from '@/Layouts/SettingsLayout';
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
    follow_up_threshold_days: number;
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
            follow_up_threshold_days: settings.follow_up_threshold_days,
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
                <form onSubmit={submit}>
                    <SettingsSection
                        title="Delivery"
                        description="Where and when digests, instant alerts, and the weekly summary are sent."
                    >
                        <div className="flex flex-col gap-5">
                            <div className="flex flex-col gap-2">
                                <Label htmlFor="notification_email">Notification email</Label>
                                <Input
                                    id="notification_email"
                                    name="notification_email"
                                    type="email"
                                    value={data.notification_email}
                                    onChange={(event) => setData('notification_email', event.target.value)}
                                    autoComplete="email"
                                    required
                                />
                                <p className="text-sm text-muted-foreground">
                                    Where digests, instant alerts, and the weekly summary are sent.
                                </p>
                                {errors.notification_email && (
                                    <p className="text-sm text-destructive">{errors.notification_email}</p>
                                )}
                            </div>
                            <div className="flex flex-col gap-2">
                                <Label htmlFor="digest_send_time">Daily digest time</Label>
                                <Input
                                    id="digest_send_time"
                                    name="digest_send_time"
                                    type="time"
                                    value={data.digest_send_time}
                                    onChange={(event) => setData('digest_send_time', event.target.value)}
                                    required
                                />
                                <p className="text-sm text-muted-foreground">
                                    Wall-clock time in your timezone the daily digest is dispatched.
                                </p>
                                {errors.digest_send_time && (
                                    <p className="text-sm text-destructive">{errors.digest_send_time}</p>
                                )}
                            </div>
                        </div>
                    </SettingsSection>

                    <SettingsSection
                        title="Follow-ups"
                        description="When an applied or screening application counts as quiet."
                    >
                        <div className="flex flex-col gap-2">
                            <Label htmlFor="follow_up_threshold_days">Follow up after (days without activity)</Label>
                            <Input
                                id="follow_up_threshold_days"
                                name="follow_up_threshold_days"
                                type="number"
                                inputMode="numeric"
                                min={1}
                                max={60}
                                step={1}
                                value={data.follow_up_threshold_days}
                                onChange={(event) => setData('follow_up_threshold_days', Number(event.target.value))}
                                required
                            />
                            <p className="text-sm text-muted-foreground">
                                Between 1 and 60. Applications past their next step date are flagged regardless.
                            </p>
                            {errors.follow_up_threshold_days && (
                                <p className="text-sm text-destructive">{errors.follow_up_threshold_days}</p>
                            )}
                        </div>
                    </SettingsSection>

                    <SettingsSection
                        title="Do-not-disturb window"
                        description="Instant alerts that land in this window are deferred to the next allowed minute, not dropped."
                    >
                        <fieldset className="grid grid-cols-2 gap-4">
                            <legend className="sr-only">Do-not-disturb window</legend>
                            <div className="flex flex-col gap-2">
                                <Label htmlFor="dnd_start">From</Label>
                                <Input
                                    id="dnd_start"
                                    name="dnd_start"
                                    type="time"
                                    value={data.dnd_start}
                                    onChange={(event) => setData('dnd_start', event.target.value)}
                                    required
                                />
                                {errors.dnd_start && (
                                    <p className="text-sm text-destructive">{errors.dnd_start}</p>
                                )}
                            </div>
                            <div className="flex flex-col gap-2">
                                <Label htmlFor="dnd_end">To</Label>
                                <Input
                                    id="dnd_end"
                                    name="dnd_end"
                                    type="time"
                                    value={data.dnd_end}
                                    onChange={(event) => setData('dnd_end', event.target.value)}
                                    required
                                />
                                {errors.dnd_end && (
                                    <p className="text-sm text-destructive">{errors.dnd_end}</p>
                                )}
                            </div>
                        </fieldset>
                    </SettingsSection>

                    <SettingsSection
                        title="Digests"
                        description="Choose which summaries you receive."
                    >
                        <div className="flex flex-col gap-5">
                            <label className="flex items-start justify-between gap-4">
                                <span className="flex min-w-0 flex-col gap-0.5">
                                    <span className="text-base font-medium text-foreground sm:text-sm">Skip empty digests</span>
                                    <span className="text-base text-pretty text-muted-foreground sm:text-sm">
                                        Suppress the daily digest when nothing new happened.
                                    </span>
                                </span>
                                <span className="group relative inline-flex w-11 shrink-0 rounded-full bg-muted p-0.5 inset-ring inset-ring-foreground/5 outline-offset-2 outline-ring has-checked:bg-primary has-focus-visible:outline-2 sm:w-9">
                                    <span className="aspect-square w-1/2 rounded-full bg-white shadow-xs ring-1 ring-black/5 group-has-checked:translate-x-full" />
                                    <input
                                        type="checkbox"
                                        name="digest_skip_empty"
                                        checked={data.digest_skip_empty}
                                        onChange={(event) => setData('digest_skip_empty', event.target.checked)}
                                        className="absolute inset-0 size-full appearance-none focus:outline-hidden"
                                    />
                                </span>
                            </label>
                            <label className="flex items-start justify-between gap-4">
                                <span className="flex min-w-0 flex-col gap-0.5">
                                    <span className="text-base font-medium text-foreground sm:text-sm">Weekly summary</span>
                                    <span className="text-base text-pretty text-muted-foreground sm:text-sm">
                                        Send a weekly roundup of activity and stale A-tier companies.
                                    </span>
                                </span>
                                <span className="group relative inline-flex w-11 shrink-0 rounded-full bg-muted p-0.5 inset-ring inset-ring-foreground/5 outline-offset-2 outline-ring has-checked:bg-primary has-focus-visible:outline-2 sm:w-9">
                                    <span className="aspect-square w-1/2 rounded-full bg-white shadow-xs ring-1 ring-black/5 group-has-checked:translate-x-full" />
                                    <input
                                        type="checkbox"
                                        name="weekly_summary_enabled"
                                        checked={data.weekly_summary_enabled}
                                        onChange={(event) => setData('weekly_summary_enabled', event.target.checked)}
                                        className="absolute inset-0 size-full appearance-none focus:outline-hidden"
                                    />
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
                        </div>
                    </SettingsSection>
                </form>
            </SettingsLayout>
        </>
    );
}
