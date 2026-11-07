import { NativeSelect } from '@/components/ui/native-select';
import { cn } from '@/lib/utils';

import type { ApplicationsCounts, ApplicationsFilters, ApplicationsView, StatusOption } from './types';

interface FilterBarProps {
    filters: ApplicationsFilters;
    statuses: StatusOption[];
    counts: ApplicationsCounts;
    onChange: (patch: Partial<ApplicationsFilters>) => void;
}

export function FilterBar({ filters, statuses, counts, onChange }: FilterBarProps) {
    const views: ReadonlyArray<{ value: ApplicationsView; label: string; count?: number }> = [
        { value: 'all', label: 'All' },
        { value: 'attention', label: 'Needs attention', count: counts.attention },
        { value: 'upcoming', label: 'Upcoming', count: counts.upcoming },
    ];

    return (
        <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div role="group" aria-label="View" className="flex items-center gap-1 overflow-x-auto shadow-[inset_0_-1px_0_var(--border)]">
                {views.map((view) => {
                    const active = filters.view === view.value;
                    return (
                        <button
                            key={view.value}
                            type="button"
                            onClick={() => onChange({ view: view.value })}
                            aria-pressed={active}
                            className={cn(
                                'min-h-10 shrink-0 border-b-2 px-3 py-2 text-base whitespace-nowrap sm:min-h-0 sm:text-sm',
                                active
                                    ? 'border-foreground font-medium text-foreground'
                                    : 'border-transparent text-muted-foreground hover:text-foreground',
                            )}
                        >
                            {view.label}
                            {view.count !== undefined ? (
                                <>
                                    {' '}
                                    <span className="text-xs tabular-nums text-muted-foreground">{view.count}</span>
                                </>
                            ) : null}
                        </button>
                    );
                })}
            </div>

            {filters.view !== 'upcoming' ? (
                <NativeSelect
                    aria-label="Stage"
                    value={filters.stage}
                    onChange={(e) => onChange({ stage: e.target.value as ApplicationsFilters['stage'] })}
                >
                    <option value="all">All stages</option>
                    {statuses.map((status) => (
                        <option key={status.value} value={status.value}>
                            {status.label}
                        </option>
                    ))}
                </NativeSelect>
            ) : null}
        </div>
    );
}
