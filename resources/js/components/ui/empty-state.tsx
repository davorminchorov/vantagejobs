import * as React from 'react';

import { cn } from '@/lib/utils';

interface EmptyStateProps extends React.HTMLAttributes<HTMLDivElement> {
    /** Optional action (button or link) rendered under the message. */
    action?: React.ReactNode;
}

function EmptyState({ className, children, action, ...props }: EmptyStateProps) {
    return (
        <div
            className={cn(
                'flex flex-col items-center justify-center gap-3 rounded-xl border border-dashed border-border px-6 py-10 text-center text-base text-pretty text-muted-foreground sm:text-sm',
                className,
            )}
            {...props}
        >
            <div>{children}</div>
            {action ? <div>{action}</div> : null}
        </div>
    );
}

export { EmptyState };
