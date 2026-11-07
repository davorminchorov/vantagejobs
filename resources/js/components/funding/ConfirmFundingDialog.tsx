import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

import { Button } from '@/components/ui/button';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

import { dateInputValue, formatUsd, onFileLabel } from './helpers';
import type { FundingCandidateItem, FundingCompanyGroup } from './types';

const COMMON_STAGES = ['Pre-seed', 'Seed', 'Series A', 'Series B', 'Series C', 'Series D', 'Series E', 'Growth', 'IPO', 'Acquired'];

export interface ConfirmTarget {
    candidate: FundingCandidateItem;
    company: FundingCompanyGroup['company'];
}

interface FormState {
    stage: string;
    amount_usd: string;
    raised_at: string;
    notes: string;
}

type FormErrors = Partial<Record<keyof FormState, string>>;

function initialState(candidate: FundingCandidateItem): FormState {
    return {
        stage: candidate.stage ?? '',
        amount_usd: candidate.amount_usd !== null ? String(candidate.amount_usd) : '',
        raised_at: dateInputValue(candidate.published_at),
        notes: '',
    };
}

/** Review the round parsed from a headline, then record it on the company. */
export function ConfirmFundingDialog({ target, onClose }: { target: ConfirmTarget | null; onClose: () => void }) {
    const [form, setForm] = useState<FormState>({ stage: '', amount_usd: '', raised_at: '', notes: '' });
    const [errors, setErrors] = useState<FormErrors>({});
    const [submitting, setSubmitting] = useState(false);

    useEffect(() => {
        if (target) {
            setForm(initialState(target.candidate));
            setErrors({});
        }
    }, [target]);

    if (!target) {
        return null;
    }

    const { candidate, company } = target;
    const onFile = onFileLabel(company.funding);
    const amount = Number(form.amount_usd);
    const amountHint = form.amount_usd !== '' && Number.isFinite(amount) && amount > 0 ? formatUsd(amount) : null;
    const foreignAmount = candidate.amount_label !== null && candidate.amount_usd === null ? candidate.amount_label : null;
    const olderThanOnFile = company.funding.raised_at !== null && form.raised_at !== '' && form.raised_at < company.funding.raised_at;

    const update = (field: keyof FormState) => (event: React.ChangeEvent<HTMLInputElement>) =>
        setForm((current) => ({ ...current, [field]: event.target.value }));

    function submit(event: React.FormEvent) {
        event.preventDefault();
        setSubmitting(true);
        router.post(
            `/insights/funding-candidates/${candidate.id}/confirm`,
            {
                stage: form.stage.trim() || null,
                amount_usd: form.amount_usd === '' ? null : Number(form.amount_usd),
                raised_at: form.raised_at || null,
                notes: form.notes.trim() || null,
            },
            {
                preserveScroll: true,
                onSuccess: onClose,
                onError: (received) => setErrors(received as FormErrors),
                onFinish: () => setSubmitting(false),
            },
        );
    }

    return (
        <Dialog open onOpenChange={(open) => (open ? null : onClose())}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Record funding for {company.name}</DialogTitle>
                    <DialogDescription className="text-pretty">{candidate.headline}</DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="funding-stage">Round</Label>
                            <Input
                                id="funding-stage"
                                list="funding-stage-options"
                                value={form.stage}
                                onChange={update('stage')}
                                placeholder="e.g. Series B"
                                maxLength={32}
                            />
                            <datalist id="funding-stage-options">
                                {COMMON_STAGES.map((stage) => (
                                    <option key={stage} value={stage} />
                                ))}
                            </datalist>
                            {errors.stage ? <p className="text-sm text-destructive">{errors.stage}</p> : null}
                        </div>
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="funding-raised-at">Raised on</Label>
                            <Input id="funding-raised-at" type="date" value={form.raised_at} onChange={update('raised_at')} />
                            {errors.raised_at ? <p className="text-sm text-destructive">{errors.raised_at}</p> : null}
                        </div>
                    </div>

                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="funding-amount">Amount (USD)</Label>
                        <Input
                            id="funding-amount"
                            type="number"
                            inputMode="numeric"
                            min={0}
                            step={1}
                            value={form.amount_usd}
                            onChange={update('amount_usd')}
                            placeholder="e.g. 20000000"
                            aria-describedby="funding-amount-hint"
                        />
                        <p id="funding-amount-hint" className="text-sm text-muted-foreground">
                            {amountHint
                                ? amountHint
                                : foreignAmount
                                  ? `The headline says ${foreignAmount}. Enter the USD amount if you know it.`
                                  : 'Leave empty if the headline does not say.'}
                        </p>
                        {errors.amount_usd ? <p className="text-sm text-destructive">{errors.amount_usd}</p> : null}
                    </div>

                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="funding-notes">Notes</Label>
                        <Input
                            id="funding-notes"
                            value={form.notes}
                            onChange={update('notes')}
                            placeholder="e.g. Led by Index Ventures"
                            maxLength={2000}
                        />
                        {errors.notes ? <p className="text-sm text-destructive">{errors.notes}</p> : null}
                    </div>

                    <p className="rounded-md bg-muted px-3 py-2 text-sm text-muted-foreground">
                        {form.raised_at === ''
                            ? "Without a date this only adds the round to the company's history; its funding fields stay as they are."
                            : olderThanOnFile
                              ? `${company.name} already has a newer round on file (${onFile}). This adds the round to its history and leaves the company's funding fields as they are.`
                              : onFile
                                ? `Replaces the funding on file (${onFile}) with this round.`
                                : "Sets the company's funding stage, amount and last-raised date."}
                    </p>

                    <DialogFooter>
                        <DialogClose asChild>
                            <Button type="button" variant="ghost">
                                Cancel
                            </Button>
                        </DialogClose>
                        <Button type="submit" disabled={submitting}>
                            Record funding
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
