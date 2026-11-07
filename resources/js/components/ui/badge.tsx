import * as React from 'react';

import { cn } from '@/lib/utils';

type BadgeVariant = 'default' | 'secondary' | 'outline' | 'success' | 'warning' | 'destructive';

interface BadgeProps extends React.HTMLAttributes<HTMLSpanElement> {
    variant?: BadgeVariant;
}

const VARIANT_CLASSES: Record<BadgeVariant, string> = {
    default: 'bg-accent text-accent-foreground inset-ring-primary/20',
    secondary: 'bg-muted text-muted-foreground inset-ring-foreground/10',
    outline: 'text-foreground inset-ring-border',
    success:
        'bg-emerald-50 text-emerald-700 inset-ring-emerald-600/20 dark:bg-emerald-400/10 dark:text-emerald-300 dark:inset-ring-emerald-400/20',
    warning: 'bg-amber-50 text-amber-800 inset-ring-amber-600/20 dark:bg-amber-400/10 dark:text-amber-300 dark:inset-ring-amber-400/20',
    destructive: 'bg-rose-50 text-rose-700 inset-ring-rose-600/20 dark:bg-rose-400/10 dark:text-rose-300 dark:inset-ring-rose-400/20',
};

function Badge({ className, variant = 'default', ...props }: BadgeProps) {
    return (
        <span
            className={cn(
                'inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-sm font-medium whitespace-nowrap inset-ring sm:text-xs',
                VARIANT_CLASSES[variant],
                className,
            )}
            {...props}
        />
    );
}

export { Badge };
