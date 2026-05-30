import { Link } from '@inertiajs/react';

export type SourceHealthStatus = 'green' | 'yellow' | 'red';

export interface SourceHealthRow {
    source_id: number;
    key: string;
    name: string;
    status: SourceHealthStatus;
    reasons: string[];
    consecutive_failures: number;
    last_run_at: string | null;
    last_error: string | null;
    confidence_p50: number | null;
    confidence_p50_prev: number | null;
    confidence_drop_pct: number | null;
}

export interface SourceHealthSummary {
    status: SourceHealthStatus;
    counts: { green: number; yellow: number; red: number };
    total: number;
    sources: SourceHealthRow[];
}

const statusDot: Record<SourceHealthStatus, string> = {
    green: 'bg-emerald-500',
    yellow: 'bg-amber-500',
    red: 'bg-rose-500',
};

const statusLabel: Record<SourceHealthStatus, string> = {
    green: 'All sources healthy',
    yellow: 'Sources need a look',
    red: 'Sources failing',
};

const overallRing: Record<SourceHealthStatus, string> = {
    green: 'border-emerald-200/70 dark:border-emerald-700/40',
    yellow: 'border-amber-200/70 dark:border-amber-700/40',
    red: 'border-rose-200/70 dark:border-rose-700/40',
};

export default function SourceHealthTile({ summary }: { summary: SourceHealthSummary }) {
    if (summary.total === 0) {
        return null;
    }

    const attention = summary.sources.filter((source) => source.status !== 'green').slice(0, 4);

    return (
        <section
            className={`mb-8 rounded-2xl border bg-card px-5 py-4 shadow-sm ${overallRing[summary.status]}`}
            aria-label="Source health"
        >
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex items-center gap-3">
                    <span
                        className={`inline-block size-2.5 shrink-0 rounded-full ${statusDot[summary.status]}`}
                        aria-hidden="true"
                    />
                    <div>
                        <h2 className="text-sm font-semibold text-foreground">{statusLabel[summary.status]}</h2>
                        <p className="text-xs text-muted-foreground">
                            <span className="font-mono tabular-nums">{summary.counts.green}</span> healthy
                            {summary.counts.yellow > 0 && (
                                <>
                                    {' · '}
                                    <span className="font-mono tabular-nums text-amber-600 dark:text-amber-400">
                                        {summary.counts.yellow}
                                    </span>{' '}
                                    watch
                                </>
                            )}
                            {summary.counts.red > 0 && (
                                <>
                                    {' · '}
                                    <span className="font-mono tabular-nums text-rose-600 dark:text-rose-400">
                                        {summary.counts.red}
                                    </span>{' '}
                                    failing
                                </>
                            )}
                            {' · '}
                            <span className="font-mono tabular-nums">{summary.total}</span> total
                        </p>
                    </div>
                </div>
                <Link
                    href="/discover/sources"
                    className="shrink-0 rounded-full bg-background px-4 py-1.5 text-xs font-semibold text-teal-700 shadow-sm hover:bg-teal-50 dark:bg-zinc-900/60 dark:text-teal-300 dark:hover:bg-zinc-800"
                >
                    View sources
                </Link>
            </div>

            {attention.length > 0 && (
                <ul className="mt-4 space-y-2 border-t border-border pt-3">
                    {attention.map((source) => (
                        <li key={source.source_id} className="flex items-start gap-3 text-sm">
                            <span
                                className={`mt-1.5 inline-block size-2 shrink-0 rounded-full ${statusDot[source.status]}`}
                                aria-hidden="true"
                            />
                            <span className="min-w-0">
                                <span className="font-medium text-foreground">{source.name}</span>
                                <span className="text-muted-foreground"> — {source.reasons.join('; ')}</span>
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
