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
                    {errors.email ? (
                        <p className="text-sm text-destructive">{errors.email}</p>
                    ) : null}
                </div>

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
                    />
                    {errors.password ? (
                        <p className="text-sm text-destructive">{errors.password}</p>
                    ) : null}
                </div>

                <div className="flex items-center justify-between gap-4">
                    <label className="flex items-center gap-2 text-base sm:text-sm">
                        <input
                            type="checkbox"
                            name="remember"
                            checked={data.remember}
                            onChange={(event) => setData('remember', event.target.checked)}
                            className="size-4 rounded border-input accent-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                        />
                        <span>Remember me</span>
                    </label>

                    <Link
                        href="/forgot-password"
                        className="text-base text-muted-foreground underline-offset-4 hover:text-foreground hover:underline sm:text-sm"
                    >
                        Forgot password?
                    </Link>
                </div>

                <Button type="submit" disabled={processing} className="w-full">
                    Sign in
                </Button>
            </form>
        </GuestLayout>
    );
}
