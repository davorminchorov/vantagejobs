import * as React from 'react';

import { cn } from '@/lib/utils';

/**
 * Small sentence-case label used as a heading for cards and form sections.
 */
function SectionLabel({ className, ...props }: React.HTMLAttributes<HTMLDivElement>) {
    return (
        <div
            className={cn(
                'text-sm font-medium text-muted-foreground',
                className,
            )}
            {...props}
        />
    );
}

export { SectionLabel };
