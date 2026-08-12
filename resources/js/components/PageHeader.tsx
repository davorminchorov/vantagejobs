import type { ReactNode } from 'react';

interface PageHeaderProps {
    title: string;
    subtitle?: ReactNode;
    actions?: ReactNode;
}

/**
 * Standard page header: plain h1, optional muted subtitle, optional actions
 * aligned to the end on wider screens.
 */
export default function PageHeader({ title, subtitle, actions }: PageHeaderProps) {
    return (
        <header className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h1 className="text-2xl font-semibold tracking-tight">{title}</h1>
                {subtitle ? <p className="text-sm text-muted-foreground">{subtitle}</p> : null}
            </div>
            {actions ? <div className="flex flex-wrap items-center gap-2">{actions}</div> : null}
        </header>
    );
}
