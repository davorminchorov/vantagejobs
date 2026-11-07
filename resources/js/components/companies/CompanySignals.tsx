import { BadgeDollarSign, Clock, TrendingDown, Unplug, type LucideIcon } from 'lucide-react';

import { Badge } from '@/components/ui/badge';

export interface SignalSource {
    funding_last_raised_at: string | null;
    has_recent_layoff_event: boolean | null;
    ats_type: string | null;
    ats_slug: string | null;
    ats_status: string;
    last_checked_at: string | null;
}

interface Signal {
    key: string;
    label: string;
    variant: 'success' | 'destructive' | 'warning' | 'secondary';
    icon: LucideIcon;
}

const DAY_MS = 24 * 60 * 60 * 1000;

/** Same rule as isUnscrapeable() on the company page. */
export function isUnscrapeable(company: Pick<SignalSource, 'ats_type' | 'ats_slug' | 'ats_status'>): boolean {
    return !company.ats_type || !company.ats_slug || company.ats_status === 'redetect_failed';
}

export function isStale(lastCheckedAt: string | null, now: number = Date.now()): boolean {
    if (!lastCheckedAt) {
        return true;
    }
    const time = new Date(lastCheckedAt).getTime();
    return Number.isNaN(time) || now - time > 7 * DAY_MS;
}

function isRecentlyFunded(raisedAt: string | null, now: number): boolean {
    if (!raisedAt) {
        return false;
    }
    const cutoff = new Date(now);
    cutoff.setMonth(cutoff.getMonth() - 18);
    return new Date(raisedAt).getTime() >= cutoff.getTime();
}

export function computeSignals(company: SignalSource, now: number = Date.now()): Signal[] {
    const signals: Signal[] = [];
    if (isRecentlyFunded(company.funding_last_raised_at, now)) {
        signals.push({ key: 'funded', label: 'Funded in the last 18 months', variant: 'success', icon: BadgeDollarSign });
    }
    if (company.has_recent_layoff_event) {
        signals.push({ key: 'layoffs', label: 'Recent layoffs', variant: 'destructive', icon: TrendingDown });
    }
    if (isUnscrapeable(company)) {
        signals.push({ key: 'unscraped', label: 'Not being scraped', variant: 'warning', icon: Unplug });
    }
    if (isStale(company.last_checked_at, now)) {
        signals.push({ key: 'stale', label: 'Not checked in the last 7 days', variant: 'secondary', icon: Clock });
    }
    return signals;
}

export default function CompanySignals({ company }: { company: SignalSource }) {
    const signals = computeSignals(company);

    if (signals.length === 0) {
        return <span className="text-muted-foreground">-</span>;
    }

    return (
        <ul className="flex items-center gap-1">
            {signals.map(({ key, label, variant, icon: Icon }) => (
                <li key={key}>
                    <Badge variant={variant} title={label} className="px-1">
                        <Icon className="size-3.5" aria-hidden="true" />
                        <span className="sr-only">{label}</span>
                    </Badge>
                </li>
            ))}
        </ul>
    );
}
