import { NativeSelect } from '@/components/ui/native-select';

import type { DiscoverOptions, Filters } from './types';

export function FilterBar({
    filters,
    options,
    onChange,
}: {
    filters: Filters;
    options: DiscoverOptions;
    onChange: (patch: Partial<Filters>) => void;
}) {
    const hasActiveFilters =
        filters.source !== null ||
        filters.seniority !== null ||
        filters.region !== null ||
        filters.has_comp ||
        filters.remote_only ||
        filters.q !== null;

    return (
        <div className="-mx-4 flex items-center gap-x-5 gap-y-3 overflow-x-auto px-4 text-base whitespace-nowrap *:shrink-0 sm:mx-0 sm:flex-wrap sm:overflow-visible sm:px-0 sm:text-sm">
            <label className="flex items-center gap-2">
                <span className="text-muted-foreground">Source</span>
                <NativeSelect
                    name="source"
                    value={filters.source ?? ''}
                    onChange={(e) => onChange({ source: e.currentTarget.value || null })}
                >
                    <option value="">All</option>
                    {options.sources.map((s) => (
                        <option key={s.value} value={s.value}>
                            {s.label}
                        </option>
                    ))}
                </NativeSelect>
            </label>
            <label className="flex items-center gap-2">
                <span className="text-muted-foreground">Seniority</span>
                <NativeSelect
                    name="seniority"
                    value={filters.seniority ?? ''}
                    onChange={(e) => onChange({ seniority: e.currentTarget.value || null })}
                >
                    <option value="">Any</option>
                    {options.seniorities.map((value) => (
                        <option key={value} value={value}>
                            {value.charAt(0).toUpperCase() + value.slice(1)}
                        </option>
                    ))}
                </NativeSelect>
            </label>
            <label className="flex items-center gap-2">
                <span className="text-muted-foreground">Region</span>
                <NativeSelect
                    name="region"
                    value={filters.region ?? ''}
                    onChange={(e) => onChange({ region: e.currentTarget.value || null })}
                >
                    <option value="">Any</option>
                    {options.regions.map((r) => (
                        <option key={r.value} value={r.value}>
                            {r.label}
                        </option>
                    ))}
                </NativeSelect>
            </label>
            <label className="flex min-h-10 items-center gap-2 sm:min-h-0">
                <input
                    type="checkbox"
                    name="has_comp"
                    className="size-4 accent-primary"
                    checked={filters.has_comp}
                    onChange={(e) => onChange({ has_comp: e.currentTarget.checked })}
                />
                Has comp
            </label>
            <label className="flex min-h-10 items-center gap-2 sm:min-h-0">
                <input
                    type="checkbox"
                    name="remote_only"
                    className="size-4 accent-primary"
                    checked={filters.remote_only}
                    onChange={(e) => onChange({ remote_only: e.currentTarget.checked })}
                />
                Remote only
            </label>
            {hasActiveFilters ? (
                <button
                    type="button"
                    onClick={() => onChange({ source: null, seniority: null, region: null, has_comp: false, remote_only: false, q: null })}
                    className="min-h-10 rounded-md text-muted-foreground underline-offset-4 hover:text-foreground hover:underline focus-visible:outline-2 focus-visible:outline-ring sm:min-h-0"
                >
                    Clear filters
                </button>
            ) : null}
        </div>
    );
}
