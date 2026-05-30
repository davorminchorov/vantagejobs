import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';

import GuestLayout from '@/Layouts/GuestLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

interface LoginPageProps {
    status?: string | null;
}

interface LoginFormData {
    email: string;
    password: string;
    remember: boolean;
    [key: string]: string | boolean;
}

export default function Login({ status }: LoginPageProps): ReactNode {
    const { data, setData, post, processing, errors, reset } = useForm<LoginFormData>({
        email: '',
        password: '',
        remember: false,
    });

    const submit = (event: FormEvent<HTMLFormElement>): void => {
        event.preventDefault();
        post('/login', {
            onFinish: () => reset('password'),
        });
    };

    return (
        <GuestLayout title="Sign in">
            <Head title="Sign in" />

            {status ? (
                <p className="mb-4 text-sm font-medium text-emerald-700 dark:text-emerald-300">{status}</p>
            ) : null}

            <form onSubmit={submit} className="space-y-4">
                <div className="space-y-2">
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
                    {errors.email ? (
                        <p className="text-destructive text-sm">{errors.email}</p>
                    ) : null}
                </div>

                <div className="space-y-2">
                    <Label htmlFor="password">Password</Label>
                    <Input
                        id="password"
                        type="password"
                        name="password"
                        autoComplete="current-password"
                        value={data.password}
                        onChange={(event) => setData('password', event.target.value)}
                        required
                    />
                    {errors.password ? (
                        <p className="text-destructive text-sm">{errors.password}</p>
                    ) : null}
                </div>

                <div className="flex items-center justify-between">
                    <label className="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            name="remember"
                            checked={data.remember}
                            onChange={(event) => setData('remember', event.target.checked)}
                            className="border-input text-primary focus-visible:ring-ring h-4 w-4 rounded"
                        />
                        <span>Remember me</span>
                    </label>

                    <Link
                        href="/forgot-password"
                        className="text-sm text-teal-700 underline-offset-4 hover:underline dark:text-teal-400"
                    >
                        Forgot password?
                    </Link>
                </div>

                <Button type="submit" disabled={processing} className="min-h-11 w-full">
                    Sign in
                </Button>
            </form>
        </GuestLayout>
    );
}
