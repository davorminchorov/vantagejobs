import type { FormEventHandler } from 'react';

import { Head, useForm } from '@inertiajs/react';

import SettingsLayout, { SettingsSection } from '@/Layouts/SettingsLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

export default function SessionsPage() {
    const { data, setData, post, processing, errors, reset, recentlySuccessful } = useForm({
        password: '',
    });

    const signOutOthers: FormEventHandler = (event) => {
        event.preventDefault();
        post('/settings/sessions/sign-out-others', {
            preserveScroll: true,
            onSuccess: () => reset('password'),
        });
    };

    return (
        <>
            <Head title="Active sessions" />
            <SettingsLayout
                title="Active sessions"
                description="Sessions are stored in Redis. Listing individual devices is deferred to a later phase; you can still sign out of every other device with one click."
            >
                <form onSubmit={signOutOthers}>
                    <SettingsSection
                        title="Sign out everywhere else"
                        description="Confirm with your current password to sign out of all other devices."
                    >
                        <div className="flex flex-col gap-5">
                            <div className="flex flex-col gap-2">
                                <Label htmlFor="password">Current password</Label>
                                <Input
                                    id="password"
                                    name="password"
                                    type="password"
                                    value={data.password}
                                    onChange={(event) => setData('password', event.target.value)}
                                    autoComplete="current-password"
                                    required
                                />
                                {errors.password && (
                                    <p className="text-sm text-destructive">{errors.password}</p>
                                )}
                            </div>
                            <div className="flex items-center gap-3">
                                <Button type="submit" variant="outline" disabled={processing}>
                                    Sign out other sessions
                                </Button>
                                {recentlySuccessful && (
                                    <span className="text-sm text-muted-foreground">Done.</span>
                                )}
                            </div>
                        </div>
                    </SettingsSection>
                </form>
            </SettingsLayout>
        </>
    );
}
