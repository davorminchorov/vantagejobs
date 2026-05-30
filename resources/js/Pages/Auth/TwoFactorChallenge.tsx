import { Head, useForm } from '@inertiajs/react';
import { useState, type FormEvent, type ReactNode } from 'react';

import GuestLayout from '@/Layouts/GuestLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

interface TwoFactorFormData {
    code: string;
    recovery_code: string;
    [key: string]: string;
}

export default function TwoFactorChallenge(): ReactNode {
    const [usingRecoveryCode, setUsingRecoveryCode] = useState<boolean>(false);

    const { data, setData, post, processing, errors, reset } = useForm<TwoFactorFormData>({
        code: '',
        recovery_code: '',
    });

    const submit = (event: FormEvent<HTMLFormElement>): void => {
        event.preventDefault();
        post('/two-factor-challenge', {
            onFinish: () => reset('code', 'recovery_code'),
        });
    };

    const toggleMode = (): void => {
        setUsingRecoveryCode((prev) => !prev);
        reset('code', 'recovery_code');
    };

    return (
        <GuestLayout title="Two-factor authentication">
            <Head title="Two-factor challenge" />

            <p className="text-muted-foreground mb-4 text-sm">
                {usingRecoveryCode
                    ? 'Enter one of your recovery codes to continue.'
                    : 'Enter the 6-digit code from your authenticator app.'}
            </p>

            <form onSubmit={submit} className="space-y-4">
                {usingRecoveryCode ? (
                    <div className="space-y-2">
                        <Label htmlFor="recovery_code">Recovery code</Label>
                        <Input
                            id="recovery_code"
                            type="text"
                            name="recovery_code"
                            autoComplete="one-time-code"
                            value={data.recovery_code}
                            onChange={(event) => setData('recovery_code', event.target.value)}
                            required
                            autoFocus
                            className="font-mono tracking-wide"
                        />
                        {errors.recovery_code ? (
                            <p className="text-destructive text-sm">{errors.recovery_code}</p>
                        ) : null}
                    </div>
                ) : (
                    <div className="space-y-2">
                        <Label htmlFor="code">Authentication code</Label>
                        <Input
                            id="code"
                            type="text"
                            name="code"
                            inputMode="numeric"
                            autoComplete="one-time-code"
                            value={data.code}
                            onChange={(event) => setData('code', event.target.value)}
                            required
                            autoFocus
                            className="font-mono tracking-wide tabular-nums"
                        />
                        {errors.code ? (
                            <p className="text-destructive text-sm">{errors.code}</p>
                        ) : null}
                    </div>
                )}

                <Button type="submit" disabled={processing} className="min-h-11 w-full">
                    Verify
                </Button>

                <button
                    type="button"
                    onClick={toggleMode}
                    className="text-muted-foreground w-full text-sm underline-offset-4 hover:underline"
                >
                    {usingRecoveryCode ? 'Use authenticator code instead' : 'Use a recovery code instead'}
                </button>
            </form>
        </GuestLayout>
    );
}
