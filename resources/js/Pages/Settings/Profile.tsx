import type { FormEventHandler } from 'react';

import { Head, useForm } from '@inertiajs/react';

import SettingsLayout from '@/Layouts/SettingsLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { User } from '@/types';

interface Props {
    user: User;
}

export default function ProfilePage({ user }: Props) {
    const { data, setData, put, processing, errors, recentlySuccessful } = useForm({
        name: user.name ?? '',
        email: user.email,
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        put('/user/profile-information', { preserveScroll: true });
    };

    return (
        <>
            <Head title="Profile" />
            <SettingsLayout
                title="Profile"
                description="Update your name and email address."
            >
                <form
                    onSubmit={submit}
                    className="max-w-lg space-y-4 rounded-2xl border border-border bg-card p-6 text-card-foreground shadow-sm"
                >
                    <div className="space-y-2">
                        <Label htmlFor="name">Name</Label>
                        <Input
                            id="name"
                            value={data.name}
                            onChange={(event) => setData('name', event.target.value)}
                            autoComplete="name"
                            required
                        />
                        {errors.name && <p className="text-sm text-destructive">{errors.name}</p>}
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="email">Email</Label>
                        <Input
                            id="email"
                            type="email"
                            value={data.email}
                            onChange={(event) => setData('email', event.target.value)}
                            autoComplete="email"
                            required
                        />
                        {errors.email && <p className="text-sm text-destructive">{errors.email}</p>}
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
