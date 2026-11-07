import { Head, useForm } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';

import GuestLayout from '@/Layouts/GuestLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

interface ForgotPasswordPageProps {
    status?: string | null;
}

interface ForgotPasswordFormData {
    email: string;
    [key: string]: string;
}

export default function ForgotPassword({ status }: ForgotPasswordPageProps): ReactNode {
    const { data, setData, post, processing, errors } = useForm<ForgotPasswordFormData>({
        email: '',
    });

    const submit = (event: FormEvent<HTMLFormElement>): void => {
        event.preventDefault();
        post('/forgot-password');
    };

    return (
        <GuestLayout title="Reset your password">
            <Head title="Forgot password" />

            <p className="text-base text-pretty text-muted-foreground sm:text-sm">
                Enter your email and we will send you a link to reset your password.
            </p>

            {status ? (
                <p className="text-base font-medium text-pretty text-emerald-700 sm:text-sm dark:text-emerald-300">{status}</p>
            ) : null}

            <form onSubmit={submit} className="flex flex-col gap-5">
                <div className="flex flex-col gap-2">
                    <Label htmlFor="email">Email</Label>
                    <Input
                        id="email"
                        type="email"
                        name="email"
                        autoComplete="username"
                        value={data.email}
                        onChange={(event) => setData('email', event.target.value)}
                        required
                        autoFocus
                    />
                    {errors.email ? <p className="text-sm text-destructive">{errors.email}</p> : null}
                </div>

                <Button type="submit" disabled={processing} className="w-full">
                    Send reset link
                </Button>
            </form>
        </GuestLayout>
    );
}
