import { formatRange, formatUsd } from './helpers';
import type { SalarySummary } from './types';

function Stat({ label, children, detail }: { label: string; children: React.ReactNode; detail: React.ReactNode }) {
    return (
        <div className="flex flex-col gap-1 py-4 sm:px-5 sm:first:pl-0">
            <dt className="text-base text-muted-foreground sm:text-sm">{label}</dt>
            <dd className="text-2xl font-semibold tracking-tight tabular-nums">{children}</dd>
            <dd className="text-sm text-pretty text-muted-foreground">{detail}</dd>
        </div>
    );
}

/** Headline numbers for the current filters. */
export function SalaryStats({ summary }: { summary: SalarySummary }) {
    const excluded = summary.hourly_excluded + summary.out_of_range_excluded;

    return (
        <dl className="grid grid-cols-1 border-y border-border sm:grid-cols-3 sm:divide-x sm:divide-border">
            <Stat label="Median salary" detail="midpoint of each posted range, in USD">
                {formatUsd(summary.median)}
            </Stat>
            <Stat label="Middle half" detail="25th to 75th percentile">
                {formatRange(summary.p25, summary.p75)}
            </Stat>
            <Stat
                label="Samples"
                detail={
                    excluded === 0 ? (
                        'listings with a posted annual range'
                    ) : (
                        <span className="tabular-nums">
                            {[
                                summary.hourly_excluded > 0 ? `${summary.hourly_excluded} hourly` : null,
                                summary.out_of_range_excluded > 0 ? `${summary.out_of_range_excluded} implausible` : null,
                            ]
                                .filter(Boolean)
                                .join(' and ')}{' '}
                            left out
                        </span>
                    )
                }
            >
                {summary.samples.toLocaleString()}
            </Stat>
        </dl>
    );
}
