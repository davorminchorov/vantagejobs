import { useEffect, useState } from 'react';

import { Head, router } from '@inertiajs/react';

import SettingsLayout from '@/Layouts/SettingsLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

interface Props {
    two_factor_enabled: boolean;
    two_factor_confirmed: boolean;
}

export default function TwoFactorPage({ two_factor_enabled, two_factor_confirmed }: Props) {
    const [qrSvg, setQrSvg] = useState<string | null>(null);
    const [recoveryCodes, setRecoveryCodes] = useState<string[] | null>(null);
    const [confirmCode, setConfirmCode] = useState('');
    const [confirmError, setConfirmError] = useState<string | null>(null);

    useEffect(() => {
        if (two_factor_enabled && !two_factor_confirmed) {
            void loadQr();
        }
        if (two_factor_enabled) {
            void loadRecoveryCodes();
        }
    }, [two_factor_enabled, two_factor_confirmed]);

    async function loadQr(): Promise<void> {
        const res = await fetch('/user/two-factor-qr-code', { headers: { Accept: 'application/json' } });
        if (res.ok) {
            const data = (await res.json()) as { svg: string };
            setQrSvg(data.svg);
        }
    }

    async function loadRecoveryCodes(): Promise<void> {
        const res = await fetch('/user/two-factor-recovery-codes', { headers: { Accept: 'application/json' } });
        if (res.ok) {
            const data = (await res.json()) as string[];
            setRecoveryCodes(data);
        }
    }

    function enable(): void {
        router.post('/user/two-factor-authentication', {}, { preserveScroll: true });
    }

    function disable(): void {
        router.delete('/user/two-factor-authentication', { preserveScroll: true });
    }

    function regenerateCodes(): void {
        router.post('/user/two-factor-recovery-codes', {}, {
            preserveScroll: true,
            onSuccess: () => {
                void loadRecoveryCodes();
            },
        });
    }

    function confirm(): void {
        setConfirmError(null);
        router.post(
            '/user/confirmed-two-factor-authentication',
            { code: confirmCode },
            {
                preserveScroll: true,
                onError: (errors) => {
                    setConfirmError(errors.code ?? 'Confirmation failed.');
                },
                onSuccess: () => {
                    setConfirmCode('');
                },
            },
        );
    }

    return (
        <>
            <Head title="Two-factor authentication" />
            <SettingsLayout
                title="Two-factor authentication"
                description="Protect your account with a TOTP authenticator app."
            >
                {!two_factor_enabled && (
                    <div className="space-y-3">
                        <p className="text-sm text-muted-foreground">
                            Two-factor authentication is not enabled.
                        </p>
                        <Button onClick={enable}>Enable two-factor authentication</Button>
                    </div>
                )}

                {two_factor_enabled && !two_factor_confirmed && (
                    <div className="max-w-lg space-y-4">
                        <div>
                            <h3 className="text-sm font-medium text-foreground">Scan the QR code</h3>
                            <p className="text-sm text-muted-foreground">
                                Open your authenticator app and scan this code.
                            </p>
                        </div>
                        {qrSvg && (
                            <div
                                className="inline-block rounded-xl border border-border bg-muted p-3"
                                dangerouslySetInnerHTML={{ __html: qrSvg }}
                            />
                        )}
                        <div className="space-y-2">
                            <Label htmlFor="confirm-code">Verification code</Label>
                            <Input
                                id="confirm-code"
                                value={confirmCode}
                                onChange={(event) => setConfirmCode(event.target.value)}
                                placeholder="6-digit code"
                                inputMode="numeric"
                                autoComplete="one-time-code"
                            />
                            {confirmError && <p className="text-sm text-destructive">{confirmError}</p>}
                        </div>
                        <div className="flex gap-2">
                            <Button onClick={confirm}>Confirm</Button>
                            <Button variant="outline" onClick={disable}>
                                Cancel
                            </Button>
                        </div>
                    </div>
                )}

                {two_factor_enabled && two_factor_confirmed && (
                    <div className="space-y-4">
                        <span className="inline-flex items-center gap-1.5 rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-medium text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-200">
                            Two-factor authentication is active
                        </span>

                        {recoveryCodes && recoveryCodes.length > 0 && (
                            <div className="max-w-lg space-y-2">
                                <h3 className="text-sm font-medium text-foreground">Recovery codes</h3>
                                <p className="text-sm text-muted-foreground">
                                    Store these somewhere safe. Each code can be used once.
                                </p>
                                <ul className="grid grid-cols-2 gap-2 rounded-xl border border-border bg-muted p-3 font-mono text-sm tabular-nums">
                                    {recoveryCodes.map((code) => (
                                        <li key={code}>{code}</li>
                                    ))}
                                </ul>
                            </div>
                        )}

                        <div className="flex flex-wrap gap-2">
                            <Button variant="outline" onClick={regenerateCodes}>
                                Regenerate recovery codes
                            </Button>
                            <Button variant="destructive" onClick={disable}>
                                Disable two-factor authentication
                            </Button>
                        </div>
                    </div>
                )}
            </SettingsLayout>
        </>
    );
}
