import * as React from 'react';

import { cn } from '@/lib/utils';

type BadgeVariant = 'default' | 'secondary' | 'outline' | 'success' | 'warning' | 'destructive';

interface BadgeProps extends React.HTMLAttributes<HTMLSpanElement> {
    variant?: BadgeVariant;
}

const VARIANT_CLASSES: Record<BadgeVariant, string> = {
    default: 'bg-primary text-primary-foreground border-transparent',
    secondary: 'bg-secondary text-secondary-foreground border-transparent',
    outline: 'border-border text-foreground',
    success: 'bg-emerald-100 text-emerald-700 border-transparent dark:bg-emerald-900/40 dark:text-emerald-200',
    warning: 'bg-amber-100 text-amber-700 border-transparent dark:bg-amber-900/40 dark:text-amber-200',
    destructive: 'bg-destructive text-destructive-foreground border-transparent',
};

function Badge({ className, variant = 'default', ...props }: BadgeProps) {
    return (
        <span
            className={cn(
                'inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium',
                VARIANT_CLASSES[variant],
                className,
            )}
            {...props}
        />
    );
}

export { Badge };
