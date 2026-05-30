import * as React from 'react';

import { cn } from '@/lib/utils';

/**
 * Horizontal-scroll container for wide tabular content.
 *
 * Use this around raw `<table>` markup (or any content that can exceed the
 * viewport width) so it scrolls within its own region instead of forcing
 * page-level horizontal scroll on small screens (target: 375px wide).
 *
 * The {@link Table} component in `ui/table.tsx` already includes an equivalent
 * wrapper; this helper exists for the cases that render a bare `<table>` or a
 * grid that cannot collapse.
 */
function DataTableScroll({ className, children, ...props }: React.HTMLAttributes<HTMLDivElement>) {
    return (
        <div
            className={cn(
                'w-full max-w-full overflow-x-auto [-webkit-overflow-scrolling:touch]',
                className,
            )}
            {...props}
        >
            {children}
        </div>
    );
}

export { DataTableScroll };
