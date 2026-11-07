import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

import { Button } from '@/components/ui/button';
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
        return 'n/a';
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
        <section className="flex max-w-3xl flex-col gap-4">
            <div className="flex flex-col gap-1">
                <h2 className="text-base font-semibold text-balance">Offer</h2>
                <p className="text-base text-pretty text-muted-foreground tabular-nums sm:text-sm">
                    {offer
                        ? `Current total comp ${formatUsd(offer.computed_total_comp_usd)} (USD, computed)`
                        : 'No offer recorded yet for this role.'}
                </p>
            </div>
            <form onSubmit={submit} className="flex flex-col gap-4">
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Field id="currency" label="Currency" error={form.errors.currency}>
                            <Input
                                id="currency"
                                name="currency"
                                value={form.data.currency}
                                onChange={(e) => form.setData('currency', e.target.value.toUpperCase())}
                                maxLength={3}
                                placeholder="USD"
                            />
                        </Field>
                        <Field id="base" label="Base salary" error={form.errors.base}>
                            <Input
                                type="number"
                                min={0}
                                id="base"
                                name="base"
                                value={form.data.base}
                                onChange={(e) => form.setData('base', e.target.value)}
                                placeholder="180000"
                            />
                        </Field>
                        <Field id="bonus" label="Annual bonus" error={form.errors.bonus}>
                            <Input
                                type="number"
                                min={0}
                                id="bonus"
                                name="bonus"
                                value={form.data.bonus}
                                onChange={(e) => form.setData('bonus', e.target.value)}
                            />
                        </Field>
                        <Field id="signing_bonus" label="Signing bonus" error={form.errors.signing_bonus}>
                            <Input
                                type="number"
                                min={0}
                                id="signing_bonus"
                                name="signing_bonus"
                                value={form.data.signing_bonus}
                                onChange={(e) => form.setData('signing_bonus', e.target.value)}
                            />
                        </Field>
                        <Field id="equity_shares" label="Equity shares" error={form.errors.equity_shares}>
                            <Input
                                type="number"
                                min={0}
                                id="equity_shares"
                                name="equity_shares"
                                value={form.data.equity_shares}
                                onChange={(e) => form.setData('equity_shares', e.target.value)}
                            />
                        </Field>
                        <Field id="vesting_years" label="Vesting years" error={form.errors.vesting_years}>
                            <Input
                                type="number"
                                min={1}
                                max={10}
                                id="vesting_years"
                                name="vesting_years"
                                value={form.data.vesting_years}
                                onChange={(e) => form.setData('vesting_years', e.target.value)}
                                placeholder="4"
                            />
                        </Field>
                        <Field id="equity_strike" label="Strike price" error={form.errors.equity_strike}>
                            <Input
                                type="number"
                                min={0}
                                step="0.0001"
                                id="equity_strike"
                                name="equity_strike"
                                value={form.data.equity_strike}
                                onChange={(e) => form.setData('equity_strike', e.target.value)}
                            />
                        </Field>
                        <Field id="equity_assumed_price" label="Assumed share price" error={form.errors.equity_assumed_price}>
                            <Input
                                type="number"
                                min={0}
                                step="0.0001"
                                id="equity_assumed_price"
                                name="equity_assumed_price"
                                value={form.data.equity_assumed_price}
                                onChange={(e) => form.setData('equity_assumed_price', e.target.value)}
                            />
                        </Field>
                        <Field id="decision_deadline" label="Decision deadline" error={form.errors.decision_deadline}>
                            <Input
                                type="date"
                                id="decision_deadline"
                                name="decision_deadline"
                                value={form.data.decision_deadline}
                                onChange={(e) => form.setData('decision_deadline', e.target.value)}
                            />
                        </Field>
                    </div>

                    <Field id="notes" label="Notes" error={form.errors.notes}>
                        <textarea
                            id="notes"
                            name="notes"
                            className={cn('min-h-20 w-full rounded-md border border-input bg-background px-3 py-2 text-base placeholder:text-muted-foreground focus-visible:outline-2 focus-visible:-outline-offset-1 focus-visible:outline-ring sm:text-sm')}
                            value={form.data.notes}
                            onChange={(e) => form.setData('notes', e.target.value)}
                            rows={3}
                        />
                    </Field>

                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <p className="text-sm text-muted-foreground sm:text-xs">
                            Total comp is computed in USD using static conversion rates.
                        </p>
                        <Button type="submit" disabled={form.processing}>
                            {offer ? 'Update offer' : 'Record offer'}
                        </Button>
                    </div>
                </form>

            {revisions.length > 0 && (
                <div className="flex flex-col gap-2 border-t border-border pt-4">
                    <h3 className="text-base font-medium sm:text-sm">Revision history</h3>
                    <ul role="list" className="divide-y divide-border">
                            {revisions.map((revision) => (
                                <li
                                    key={revision.id}
                                    className="flex items-center justify-between gap-3 py-2 text-base text-muted-foreground tabular-nums sm:text-sm"
                                >
                                    <span>
                                        {revision.created_at
                                            ? new Date(revision.created_at).toLocaleString()
                                            : 'n/a'}
                                    </span>
                                    <span className="font-medium text-foreground">
                                        {formatUsd(revision.computed_total_comp_usd)}
                                    </span>
                                </li>
                            ))}
                        </ul>
                </div>
            )}
        </section>
    );
}

interface FieldProps {
    id: string;
    label: string;
    error?: string;
    children: React.ReactNode;
}

function Field({ id, label, error, children }: FieldProps) {
    return (
        <div className="flex flex-col gap-1.5">
            <Label htmlFor={id} className={cn(error && 'text-destructive')}>
                {label}
            </Label>
            {children}
            {error && <p className="text-sm text-destructive">{error}</p>}
        </div>
    );
}
