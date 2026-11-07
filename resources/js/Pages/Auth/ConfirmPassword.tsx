import { Head, useForm } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';

import GuestLayout from '@/Layouts/GuestLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

interface ConfirmPasswordFormData {
    password: string;
    [key: string]: string;
}

export default function ConfirmPassword(): ReactNode {
    const { data, setData, post, processing, errors, reset } = useForm<ConfirmPasswordFormData>({
        password: '',
    });

    const submit = (event: FormEvent<HTMLFormElement>): void => {
        event.preventDefault();
        post('/user/confirm-password', {
            onFinish: () => reset('password'),
        });
    };

    return (
        <GuestLayout title="Confirm your password">
            <Head title="Confirm password" />

            <p className="text-base text-pretty text-muted-foreground sm:text-sm">
                This is a secure area. Confirm your password before continuing.
            </p>

            <form onSubmit={submit} className="flex flex-col gap-5">
                <div className="flex flex-col gap-2">
                    <Label htmlFor="password">Password</Label>
                    <Input
                        id="password"
                        type="password"
                        name="password"
                        autoComplete="current-password"
                        value={data.password}
                        onChange={(event) => setData('password', event.target.value)}
                        required
                        autoFocus
                    />
                    {errors.password ? (
                        <p className="text-sm text-destructive">{errors.password}</p>
                    ) : null}
                </div>

                <Button type="submit" disabled={processing} className="w-full">
                    Confirm
                </Button>
            </form>
        </GuestLayout>
    );
}
