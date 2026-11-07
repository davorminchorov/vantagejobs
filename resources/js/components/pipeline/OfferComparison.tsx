import { useEffect, useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { cn } from '@/lib/utils';

export interface OfferComparisonRow {
    offer_id: number;
    role_id: number;
    role_title: string | null;
    company_name: string | null;
    currency: string;
    base: number | null;
    bonus: number | null;
    signing_bonus: number | null;
    equity_shares: number | null;
    vesting_years: number | null;
    computed_total_comp_usd: number | null;
    decision_deadline: string | null;
}

interface ComparisonPayload {
    offers: OfferComparisonRow[];
    best_offer_id: number | null;
    count: number;
}

interface OfferComparisonProps {
    /** Named route 'pipeline.offers.comparison' resolved by the caller via route(). */
    endpoint: string;
}

function formatUsd(value: number | null): string {
    if (value === null) {
        return '—';
    }
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
        maximumFractionDigits: 0,
    }).format(value);
}

export default function OfferComparison({ endpoint }: OfferComparisonProps) {
    const [payload, setPayload] = useState<ComparisonPayload | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        const controller = new AbortController();

        fetch(endpoint, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal: controller.signal,
        })
            .then((response) => {
                if (!response.ok) {
                    throw new Error(`Request failed (${response.status})`);
                }
                return response.json() as Promise<ComparisonPayload>;
            })
            .then((data) => {
                setPayload(data);
                setLoading(false);
            })
            .catch((err: unknown) => {
                if (err instanceof DOMException && err.name === 'AbortError') {
                    return;
                }
                setError('Could not load offer comparison.');
                setLoading(false);
            });

        return () => controller.abort();
    }, [endpoint]);

    return (
        <section className="flex flex-col gap-4">
            <div className="flex flex-col gap-1">
                <h2 className="text-base font-semibold text-balance">Offer comparison</h2>
                <p className="text-base text-pretty text-muted-foreground sm:text-sm">
                    All active offers ranked by computed USD total comp.
                </p>
            </div>

            {loading && (
                <div className="flex flex-col gap-2">
                    {[0, 1, 2].map((i) => (
                        <div key={i} className="h-12 animate-pulse rounded-md bg-muted" />
                    ))}
                </div>
            )}

            {!loading && error && <p className="text-base text-destructive sm:text-sm">{error}</p>}

            {!loading && !error && payload && payload.count === 0 && (
                <p className="text-base text-muted-foreground sm:text-sm">No roles are currently at the offer stage.</p>
            )}

            {!loading && !error && payload && payload.count > 0 && (
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Role</TableHead>
                            <TableHead>Company</TableHead>
                            <TableHead className="text-right">Base</TableHead>
                            <TableHead className="text-right">Equity</TableHead>
                            <TableHead className="text-right">Total (USD)</TableHead>
                            <TableHead>Deadline</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {payload.offers.map((row) => {
                            const isBest = row.offer_id === payload.best_offer_id;
                            return (
                                <TableRow key={row.offer_id} className={cn(isBest && 'bg-muted/50')}>
                                    <TableCell className="font-medium text-foreground">
                                        <div className="flex items-center gap-2">
                                            {row.role_title ?? `Role #${row.role_id}`}
                                            {isBest && <Badge variant="success">Top comp</Badge>}
                                        </div>
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">{row.company_name ?? '—'}</TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {row.base !== null ? `${row.currency} ${row.base.toLocaleString()}` : '—'}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {row.equity_shares !== null ? `${row.equity_shares.toLocaleString()} sh` : '—'}
                                    </TableCell>
                                    <TableCell className="text-right font-medium tabular-nums text-foreground">
                                        {formatUsd(row.computed_total_comp_usd)}
                                    </TableCell>
                                    <TableCell className="tabular-nums text-muted-foreground">
                                        {row.decision_deadline ?? '—'}
                                    </TableCell>
                                </TableRow>
                            );
                        })}
                    </TableBody>
                </Table>
            )}
        </section>
    );
}
