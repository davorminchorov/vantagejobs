import { EmptyState } from '@/components/ui/empty-state';
import { cn } from '@/lib/utils';

import { formatUsd, MIN_RELIABLE_SAMPLES, percentOf, rangeScale } from './helpers';
import type { SalaryBucket } from './types';

export interface RangeBarsProps {
    rows: SalaryBucket[];
    /** Key of the row matching the current filter, highlighted. */
    activeKey: string;
    /** Clicking a row filters to it; clicking the active row clears the filter. */
    onSelect: (key: string) => void;
    /** Rows that can't be used as a filter value (e.g. "Region not stated"). */
    unselectableKeys?: string[];
    emptyMessage: string;
}

/**
 * One row per bucket: a bar from P25 to P75 with a tick at the median, on
 * an axis shared by every row so the bars compare directly. The numbers are
 * printed beside each bar, so nothing relies on reading the bar alone.
 */
export function RangeBars({ rows, activeKey, onSelect, unselectableKeys = [], emptyMessage }: RangeBarsProps) {
    if (rows.length === 0) {
        return <EmptyState>{emptyMessage}</EmptyState>;
    }

    const scale = rangeScale(rows);

    return (
        <div className="flex flex-col">
            <ul role="list" className="flex flex-col">
                {rows.map((row) => {
                    const active = row.key === activeKey;
                    const reliable = row.samples >= MIN_RELIABLE_SAMPLES;
                    const selectable = !unselectableKeys.includes(row.key);
                    const content = (
                        <>
                            <span className="flex min-w-0 items-baseline justify-between gap-2 sm:contents">
                                <span
                                    className={cn(
                                        'truncate text-base sm:text-sm',
                                        active ? 'font-semibold text-foreground' : 'text-foreground',
                                    )}
                                >
                                    {row.label}
                                </span>
                                <span className="text-sm text-muted-foreground tabular-nums sm:order-last sm:text-right">
                                    <span className={cn('text-foreground', !reliable && 'text-muted-foreground')}>
                                        {formatUsd(row.median)}
                                    </span>
                                    <span className="hidden sm:inline">
                                        {' '}
                                        ({formatUsd(row.p25)} to {formatUsd(row.p75)})
                                    </span>
                                    <span className="block text-xs">
                                        {row.samples} {row.samples === 1 ? 'sample' : 'samples'}
                                        {reliable ? '' : ', too few to rely on'}
                                    </span>
                                </span>
                            </span>
                            <Track row={row} scale={scale} active={active} reliable={reliable} />
                        </>
                    );
                    const rowClass =
                        'grid w-full grid-cols-1 items-center gap-1.5 rounded-md px-2 py-2.5 text-left sm:grid-cols-[9rem_1fr_11rem] sm:gap-4';

                    return (
                        <li key={row.key}>
                            {selectable ? (
                                <button
                                    type="button"
                                    onClick={() => onSelect(active ? '' : row.key)}
                                    aria-pressed={active}
                                    className={cn(
                                        rowClass,
                                        'hover:bg-muted focus-visible:outline-2 focus-visible:outline-ring',
                                        active && 'bg-muted',
                                    )}
                                >
                                    {content}
                                </button>
                            ) : (
                                <div className={rowClass}>{content}</div>
                            )}
                        </li>
                    );
                })}
            </ul>
            <div className="hidden grid-cols-[9rem_1fr_11rem] gap-4 px-2 pt-1 sm:grid" aria-hidden="true">
                <span />
                <span className="relative h-4 text-xs text-muted-foreground tabular-nums">
                    {scale.ticks.map((tick) => (
                        <span key={tick} className="absolute -translate-x-1/2" style={{ left: `${percentOf(tick, scale)}%` }}>
                            {formatUsd(tick)}
                        </span>
                    ))}
                </span>
                <span />
            </div>
        </div>
    );
}

function Track({
    row,
    scale,
    active,
    reliable,
}: {
    row: SalaryBucket;
    scale: ReturnType<typeof rangeScale>;
    active: boolean;
    reliable: boolean;
}) {
    if (row.p25 === null || row.p75 === null || row.median === null) {
        return <span className="h-3" />;
    }

    const left = percentOf(row.p25, scale);
    const width = Math.max(percentOf(row.p75, scale) - left, 0.75);
    const median = percentOf(row.median, scale);

    return (
        <span className="relative block h-3" aria-hidden="true">
            {scale.ticks.map((tick) => (
                <span key={tick} className="absolute inset-y-[-6px] w-px bg-border" style={{ left: `${percentOf(tick, scale)}%` }} />
            ))}
            <span
                className={cn(
                    'absolute inset-y-0 rounded-sm',
                    active ? 'bg-primary/45' : 'bg-primary/25',
                    !reliable && 'bg-muted-foreground/20',
                )}
                style={{ left: `${left}%`, width: `${width}%` }}
            />
            <span
                className={cn('absolute inset-y-[-3px] w-0.5 -translate-x-1/2 rounded-full bg-primary', !reliable && 'bg-muted-foreground')}
                style={{ left: `${median}%` }}
            />
        </span>
    );
}
