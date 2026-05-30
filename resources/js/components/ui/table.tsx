import * as React from 'react';

import { cn } from '@/lib/utils';

function Table({ className, ...props }: React.HTMLAttributes<HTMLTableElement>) {
    return (
        // `max-w-full` + `overflow-x-auto` keep a wide table inside its own
        // scroll region so it never forces page-level horizontal scroll at
        // 375px. `[-webkit-overflow-scrolling:touch]` gives momentum scroll
        // on iOS.
        <div className="relative w-full max-w-full overflow-x-auto [-webkit-overflow-scrolling:touch]">
            <table className={cn('w-full caption-bottom text-sm', className)} {...props} />
        </div>
    );
}

function TableHeader({ className, ...props }: React.HTMLAttributes<HTMLTableSectionElement>) {
    return <thead className={cn('[&_tr]:border-b', className)} {...props} />;
}

function TableBody({ className, ...props }: React.HTMLAttributes<HTMLTableSectionElement>) {
    return <tbody className={cn('[&_tr:last-child]:border-0', className)} {...props} />;
}

function TableRow({
    className,
    ref,
    ...props
}: React.HTMLAttributes<HTMLTableRowElement> & { ref?: React.Ref<HTMLTableRowElement> }) {
    return (
        <tr
            ref={ref}
            className={cn(
                'border-b transition-colors hover:bg-muted/50 data-[state=selected]:bg-muted',
                className,
            )}
            {...props}
        />
    );
}

function TableHead({ className, ...props }: React.ThHTMLAttributes<HTMLTableCellElement>) {
    return (
        <th
            className={cn(
                'h-10 px-3 text-left align-middle text-xs font-medium uppercase tracking-wide text-muted-foreground',
                className,
            )}
            {...props}
        />
    );
}

function TableCell({ className, ...props }: React.TdHTMLAttributes<HTMLTableCellElement>) {
    return <td className={cn('px-3 py-3 align-middle', className)} {...props} />;
}

export { Table, TableBody, TableCell, TableHead, TableHeader, TableRow };
