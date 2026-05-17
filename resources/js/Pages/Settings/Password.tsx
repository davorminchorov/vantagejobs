import type { FormEventHandler } from 'react';

import { Head, useForm } from '@inertiajs/react';

import SettingsLayout from '@/Layouts/SettingsLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

export default function PasswordPage() {
    const { data, setData, put, processing, errors, reset, recentlySuccessful } = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        put('/user/password', {
            preserveScroll: true,
            onSuccess: () => reset(),
        });
    };

    return (
        <>
            <Head title="Password" />
            <SettingsLayout
                title="Password"
                description="Choose a strong password — at least 12 characters."
            >
                <form onSubmit={submit} className="max-w-lg space-y-4">
                    <div className="space-y-2">
                        <Label htmlFor="current_password">Current password</Label>
                        <Input
                            id="current_password"
                            type="password"
                            value={data.current_password}
                            onChange={(event) => setData('current_password', event.target.value)}
                            autoComplete="current-password"
                            required
                        />
                        {errors.current_password && (
                            <p className="text-sm text-destructive">{errors.current_password}</p>
                        )}
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="password">New password</Label>
                        <Input
                            id="password"
                            type="password"
                            value={data.password}
                            onChange={(event) => setData('password', event.target.value)}
                            autoComplete="new-password"
                            required
                        />
                        {errors.password && (
                            <p className="text-sm text-destructive">{errors.password}</p>
                        )}
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="password_confirmation">Confirm new password</Label>
                        <Input
                            id="password_confirmation"
                            type="password"
                            value={data.password_confirmation}
                            onChange={(event) => setData('password_confirmation', event.target.value)}
                            autoComplete="new-password"
                            required
                        />
                    </div>

                    <div className="flex items-center gap-3">
                        <Button type="submit" disabled={processing}>
                            Update password
                        </Button>
                        {recentlySuccessful && (
                            <span className="text-sm text-muted-foreground">Password updated.</span>
                        )}
                    </div>
                </form>
            </SettingsLayout>
        </>
    );
}
