import * as React from 'react';

import { cn } from '@/lib/utils';

/**
 * ALL-CAPS mono section label used as a heading for cards and form sections.
 */
function SectionLabel({ className, ...props }: React.HTMLAttributes<HTMLDivElement>) {
    return (
        <div
            className={cn(
                'font-mono text-xs font-semibold uppercase tracking-wide text-muted-foreground',
                className,
            )}
            {...props}
        />
    );
}

export { SectionLabel };
