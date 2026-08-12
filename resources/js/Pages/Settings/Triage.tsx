import type { FormEventHandler } from 'react';

import { Head, useForm } from '@inertiajs/react';

import SettingsLayout from '@/Layouts/SettingsLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

interface TriageSettings {
    auto_dismiss_threshold: number;
    dismissed_retention_days: number;
    listing_expiry_days: number;
}

interface Props {
    settings: TriageSettings;
}

export default function TriagePage({ settings }: Props) {
    const { data, setData, post, processing, errors, recentlySuccessful } =
        useForm<TriageSettings>({
            auto_dismiss_threshold: settings.auto_dismiss_threshold,
            dismissed_retention_days: settings.dismissed_retention_days,
            listing_expiry_days: settings.listing_expiry_days,
        });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        post('/settings/triage', { preserveScroll: true });
    };

    return (
        <>
            <Head title="Triage settings" />
            <SettingsLayout
                title="Triage"
                description="Tune how aggressively low-confidence listings are auto-dismissed and how long dismissals are kept."
            >
                <form
                    onSubmit={submit}
                    className="max-w-lg space-y-6 rounded-2xl border border-border bg-card p-6 text-card-foreground shadow-sm"
                >
                    <div className="space-y-2">
                        <Label htmlFor="auto_dismiss_threshold">
                            Auto-dismiss confidence threshold
                        </Label>
                        <Input
                            id="auto_dismiss_threshold"
                            type="number"
                            min={0}
                            max={100}
                            value={data.auto_dismiss_threshold}
                            onChange={(event) =>
                                setData('auto_dismiss_threshold', event.target.valueAsNumber)
                            }
                            required
                        />
                        <p className="text-xs text-muted-foreground">
                            Listings whose parser confidence falls below this percentage are
                            auto-dismissed from the discover queue.
                        </p>
                        {errors.auto_dismiss_threshold && (
                            <p className="text-sm text-destructive">
                                {errors.auto_dismiss_threshold}
                            </p>
                        )}
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="dismissed_retention_days">
                            Dismissed listing retention (days)
                        </Label>
                        <Input
                            id="dismissed_retention_days"
                            type="number"
                            min={1}
                            max={365}
                            value={data.dismissed_retention_days}
                            onChange={(event) =>
                                setData('dismissed_retention_days', event.target.valueAsNumber)
                            }
                            required
                        />
                        <p className="text-xs text-muted-foreground">
                            How long dismissed listings are kept before the retention sweep deletes
                            them.
                        </p>
                        {errors.dismissed_retention_days && (
                            <p className="text-sm text-destructive">
                                {errors.dismissed_retention_days}
                            </p>
                        )}
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="listing_expiry_days">New listing expiry (days)</Label>
                        <Input
                            id="listing_expiry_days"
                            type="number"
                            min={1}
                            max={365}
                            value={data.listing_expiry_days}
                            onChange={(event) =>
                                setData('listing_expiry_days', event.target.valueAsNumber)
                            }
                            required
                        />
                        <p className="text-xs text-muted-foreground">
                            New listings older than this (by posted date, or first seen when
                            undated) move to the Expired tab in the daily sweep. Kept, snoozed,
                            and bookmarked listings never expire.
                        </p>
                        {errors.listing_expiry_days && (
                            <p className="text-sm text-destructive">{errors.listing_expiry_days}</p>
                        )}
                    </div>

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
