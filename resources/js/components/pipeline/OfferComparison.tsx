import { useEffect, useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
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
        <Card>
            <CardHeader>
                <CardTitle className="text-lg">Offer comparison</CardTitle>
                <CardDescription>All active offers ranked by computed USD total comp.</CardDescription>
            </CardHeader>
            <CardContent>
                {loading && (
                    <div className="space-y-2">
                        {[0, 1, 2].map((i) => (
                            <div key={i} className="h-12 animate-pulse rounded-md bg-muted" />
                        ))}
                    </div>
                )}

                {!loading && error && <p className="text-sm text-destructive">{error}</p>}

                {!loading && !error && payload && payload.count === 0 && (
                    <p className="text-sm text-muted-foreground">No roles are currently at the offer stage.</p>
                )}

                {!loading && !error && payload && payload.count > 0 && (
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b text-left text-xs uppercase tracking-wide text-muted-foreground">
                                    <th className="py-2 pr-4 font-medium">Role</th>
                                    <th className="py-2 pr-4 font-medium">Company</th>
                                    <th className="py-2 pr-4 text-right font-medium">Base</th>
                                    <th className="py-2 pr-4 text-right font-medium">Equity</th>
                                    <th className="py-2 pr-4 text-right font-medium">Total (USD)</th>
                                    <th className="py-2 font-medium">Deadline</th>
                                </tr>
                            </thead>
                            <tbody>
                                {payload.offers.map((row) => {
                                    const isBest = row.offer_id === payload.best_offer_id;
                                    return (
                                        <tr
                                            key={row.offer_id}
                                            className={cn('border-b last:border-0', isBest && 'bg-primary/5')}
                                        >
                                            <td className="py-2 pr-4 font-medium text-foreground">
                                                <div className="flex items-center gap-2">
                                                    {row.role_title ?? `Role #${row.role_id}`}
                                                    {isBest && <Badge variant="success">Top comp</Badge>}
                                                </div>
                                            </td>
                                            <td className="py-2 pr-4 text-muted-foreground">
                                                {row.company_name ?? '—'}
                                            </td>
                                            <td className="py-2 pr-4 text-right tabular-nums">
                                                {row.base !== null ? `${row.currency} ${row.base.toLocaleString()}` : '—'}
                                            </td>
                                            <td className="py-2 pr-4 text-right tabular-nums">
                                                {row.equity_shares !== null
                                                    ? `${row.equity_shares.toLocaleString()} sh`
                                                    : '—'}
                                            </td>
                                            <td className="py-2 pr-4 text-right font-semibold tabular-nums text-foreground">
                                                {formatUsd(row.computed_total_comp_usd)}
                                            </td>
                                            <td className="py-2 text-muted-foreground">
                                                {row.decision_deadline ?? '—'}
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}
