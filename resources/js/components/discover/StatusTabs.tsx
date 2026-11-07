import { ChevronDown } from 'lucide-react';

import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';

import { MORE_STATUS_TABS, VISIBLE_STATUS_TABS, titleCaseStatus } from './helpers';

export function StatusTabs({
    current,
    counts,
    onChange,
}: {
    current: string;
    counts: Record<string, number>;
    onChange: (s: string) => void;
}) {
    const moreActive = (MORE_STATUS_TABS as readonly string[]).includes(current);

    return (
        <div className="flex items-center gap-1 overflow-x-auto shadow-[inset_0_-1px_0_var(--border)]">
            {VISIBLE_STATUS_TABS.map((status) => {
                const active = current === status;
                return (
                    <button
                        key={status}
                        type="button"
                        onClick={() => onChange(status)}
                        aria-current={active ? 'true' : undefined}
                        className={
                            'min-h-10 shrink-0 border-b-2 px-3 py-2 text-base whitespace-nowrap sm:min-h-0 sm:text-sm ' +
                            (active
                                ? 'border-foreground font-medium text-foreground'
                                : 'border-transparent text-muted-foreground hover:text-foreground')
                        }
                    >
                        {titleCaseStatus(status)} <span className="text-xs tabular-nums text-muted-foreground">{counts[status] ?? 0}</span>
                    </button>
                );
            })}
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <button
                        type="button"
                        className={
                            'min-h-10 shrink-0 border-b-2 px-3 py-2 text-base whitespace-nowrap sm:min-h-0 sm:text-sm ' +
                            (moreActive
                                ? 'border-foreground font-medium text-foreground'
                                : 'border-transparent text-muted-foreground hover:text-foreground')
                        }
                        aria-label="More status tabs"
                    >
                        {moreActive ? (
                            <>
                                {titleCaseStatus(current)}{' '}
                                <span className="text-xs tabular-nums text-muted-foreground">{counts[current] ?? 0}</span>
                            </>
                        ) : (
                            'More'
                        )}{' '}
                        <ChevronDown aria-hidden="true" className="inline size-4 align-text-bottom" />
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="start">
                    {MORE_STATUS_TABS.map((status) => (
                        <DropdownMenuItem key={status} onSelect={() => onChange(status)}>
                            {titleCaseStatus(status)}{' '}
                            <span className="ml-1 text-xs tabular-nums text-muted-foreground">{counts[status] ?? 0}</span>
                        </DropdownMenuItem>
                    ))}
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
    );
}
