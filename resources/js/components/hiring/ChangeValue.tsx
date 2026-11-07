import { cn } from '@/lib/utils';

import { signed } from './helpers';

/**
 * Signed change in open roles. The sign carries the meaning; color only
 * reinforces it. Null means there is no snapshot from that far back yet.
 */
export function ChangeValue({ value, period, className }: { value: number | null; period: string; className?: string }) {
    if (value === null) {
        return (
            <span className={cn('text-muted-foreground', className)} title={`No snapshot from ${period} ago yet`}>
                &ndash;
            </span>
        );
    }

    return (
        <span
            className={cn(
                'tabular-nums',
                value > 0 && 'text-emerald-700 dark:text-emerald-400',
                value < 0 && 'text-rose-700 dark:text-rose-400',
                value === 0 && 'text-muted-foreground',
                className,
            )}
        >
            {signed(value)}
        </span>
    );
}
