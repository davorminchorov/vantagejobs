import type { FormEventHandler } from 'react';

import { Head, useForm } from '@inertiajs/react';

import SettingsLayout from '@/Layouts/SettingsLayout';
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
                <form onSubmit={signOutOthers} className="max-w-md space-y-3">
                    <h3 className="text-sm font-medium">Sign out everywhere else</h3>
                    <p className="text-sm text-muted-foreground">
                        Confirm with your current password to sign out of all other devices.
                    </p>
                    <div className="space-y-2">
                        <Label htmlFor="password">Current password</Label>
                        <Input
                            id="password"
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
                        <Button type="submit" variant="destructive" disabled={processing}>
                            Sign out other sessions
                        </Button>
                        {recentlySuccessful && (
                            <span className="text-sm text-muted-foreground">Done.</span>
                        )}
                    </div>
                </form>
            </SettingsLayout>
        </>
    );
}
