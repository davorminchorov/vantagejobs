import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';

export interface OfferRevisionSummary {
    id: number;
    computed_total_comp_usd: number | null;
    created_at: string | null;
}

export interface OfferSummary {
    id: number;
    role_id: number;
    currency: string;
    base: number | null;
    bonus: number | null;
    signing_bonus: number | null;
    equity_shares: number | null;
    equity_strike: number | null;
    equity_assumed_price: number | null;
    vesting_years: number | null;
    computed_total_comp_usd: number | null;
    decision_deadline: string | null;
    notes: string | null;
    revisions?: OfferRevisionSummary[];
}

interface OfferFormValues {
    currency: string;
    base: string;
    bonus: string;
    signing_bonus: string;
    equity_shares: string;
    equity_strike: string;
    equity_assumed_price: string;
    vesting_years: string;
    decision_deadline: string;
    notes: string;
}

interface OfferPanelProps {
    offer: OfferSummary | null;
    /** Named route 'pipeline.offers.upsert' resolved by the caller via route(). */
    action: string;
}

function toField(value: number | null | undefined): string {
    return value === null || value === undefined ? '' : String(value);
}

function formatUsd(value: number | null | undefined): string {
    if (value === null || value === undefined) {
        return '—';
    }
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
        maximumFractionDigits: 0,
    }).format(value);
}

export default function OfferPanel({ offer, action }: OfferPanelProps) {
    const form = useForm<OfferFormValues>({
        currency: offer?.currency ?? 'USD',
        base: toField(offer?.base),
        bonus: toField(offer?.bonus),
        signing_bonus: toField(offer?.signing_bonus),
        equity_shares: toField(offer?.equity_shares),
        equity_strike: toField(offer?.equity_strike),
        equity_assumed_price: toField(offer?.equity_assumed_price),
        vesting_years: toField(offer?.vesting_years),
        decision_deadline: offer?.decision_deadline ?? '',
        notes: offer?.notes ?? '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(action, { preserveScroll: true });
    };

    const revisions = offer?.revisions ?? [];

    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-lg">Offer</CardTitle>
                <CardDescription>
                    {offer
                        ? `Current total comp ${formatUsd(offer.computed_total_comp_usd)} (USD, computed)`
                        : 'No offer recorded yet for this role.'}
                </CardDescription>
            </CardHeader>
            <CardContent>
                <form onSubmit={submit} className="space-y-4">
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Field label="Currency" error={form.errors.currency}>
                            <Input
                                value={form.data.currency}
                                onChange={(e) => form.setData('currency', e.target.value.toUpperCase())}
                                maxLength={3}
                                placeholder="USD"
                            />
                        </Field>
                        <Field label="Base salary" error={form.errors.base}>
                            <Input
                                type="number"
                                min={0}
                                value={form.data.base}
                                onChange={(e) => form.setData('base', e.target.value)}
                                placeholder="180000"
                            />
                        </Field>
                        <Field label="Annual bonus" error={form.errors.bonus}>
                            <Input
                                type="number"
                                min={0}
                                value={form.data.bonus}
                                onChange={(e) => form.setData('bonus', e.target.value)}
                            />
                        </Field>
                        <Field label="Signing bonus" error={form.errors.signing_bonus}>
                            <Input
                                type="number"
                                min={0}
                                value={form.data.signing_bonus}
                                onChange={(e) => form.setData('signing_bonus', e.target.value)}
                            />
                        </Field>
                        <Field label="Equity shares" error={form.errors.equity_shares}>
                            <Input
                                type="number"
                                min={0}
                                value={form.data.equity_shares}
                                onChange={(e) => form.setData('equity_shares', e.target.value)}
                            />
                        </Field>
                        <Field label="Vesting years" error={form.errors.vesting_years}>
                            <Input
                                type="number"
                                min={1}
                                max={10}
                                value={form.data.vesting_years}
                                onChange={(e) => form.setData('vesting_years', e.target.value)}
                                placeholder="4"
                            />
                        </Field>
                        <Field label="Strike price" error={form.errors.equity_strike}>
                            <Input
                                type="number"
                                min={0}
                                step="0.0001"
                                value={form.data.equity_strike}
                                onChange={(e) => form.setData('equity_strike', e.target.value)}
                            />
                        </Field>
                        <Field label="Assumed share price" error={form.errors.equity_assumed_price}>
                            <Input
                                type="number"
                                min={0}
                                step="0.0001"
                                value={form.data.equity_assumed_price}
                                onChange={(e) => form.setData('equity_assumed_price', e.target.value)}
                            />
                        </Field>
                        <Field label="Decision deadline" error={form.errors.decision_deadline}>
                            <Input
                                type="date"
                                value={form.data.decision_deadline}
                                onChange={(e) => form.setData('decision_deadline', e.target.value)}
                            />
                        </Field>
                    </div>

                    <Field label="Notes" error={form.errors.notes}>
                        <textarea
                            className="flex min-h-20 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                            value={form.data.notes}
                            onChange={(e) => form.setData('notes', e.target.value)}
                            rows={3}
                        />
                    </Field>

                    <div className="flex items-center justify-between">
                        <p className="text-xs text-muted-foreground">
                            Total comp is computed in USD using static conversion rates.
                        </p>
                        <Button type="submit" disabled={form.processing}>
                            {offer ? 'Update offer' : 'Record offer'}
                        </Button>
                    </div>
                </form>

                {revisions.length > 0 && (
                    <div className="mt-6 border-t pt-4">
                        <h3 className="text-sm font-medium">Revision history</h3>
                        <ul className="mt-2 space-y-1">
                            {revisions.map((revision) => (
                                <li
                                    key={revision.id}
                                    className="flex items-center justify-between text-sm text-muted-foreground"
                                >
                                    <span>
                                        {revision.created_at
                                            ? new Date(revision.created_at).toLocaleString()
                                            : '—'}
                                    </span>
                                    <span className="font-medium text-foreground">
                                        {formatUsd(revision.computed_total_comp_usd)}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

interface FieldProps {
    label: string;
    error?: string;
    children: React.ReactNode;
}

function Field({ label, error, children }: FieldProps) {
    return (
        <div className="space-y-1.5">
            <Label className={cn(error && 'text-destructive')}>{label}</Label>
            {children}
            {error && <p className="text-xs text-destructive">{error}</p>}
        </div>
    );
}
