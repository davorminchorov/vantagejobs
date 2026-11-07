import { Head, router } from '@inertiajs/react';

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { RangeBars } from '@/components/comp/RangeBars';
import { RecentSalaries } from '@/components/comp/RecentSalaries';
import { SalaryStats } from '@/components/comp/SalaryStats';
import { formatUsd } from '@/components/comp/helpers';
import type {
    SalaryBounds,
    SalaryBucket,
    SalaryFilters,
    SalaryOptions,
    SalarySample,
    SalarySummary,
    WindowFilter,
} from '@/components/comp/types';
import InsightsNav from '@/components/insights/InsightsNav';
import PageHeader from '@/components/PageHeader';
import { EmptyState } from '@/components/ui/empty-state';
import { NativeSelect } from '@/components/ui/native-select';
import { SectionLabel } from '@/components/ui/section-label';

interface Props {
    filters: SalaryFilters;
    options: SalaryOptions;
    bounds: SalaryBounds;
    summary: SalarySummary;
    by_level: SalaryBucket[];
    by_region: SalaryBucket[];
    recent: SalarySample[];
}

const DEFAULTS: SalaryFilters = { discipline: 'tech', level: 'any', region: 'any', window: 90 };

const WINDOWS: ReadonlyArray<{ value: WindowFilter; label: string }> = [
    { value: 90, label: 'Last 90 days' },
    { value: 180, label: 'Last 180 days' },
    { value: 0, label: 'All time' },
];

export default function CompInsightsPage({ filters, options, bounds, summary, by_level, by_region, recent }: Props) {
    const update = (changes: Partial<SalaryFilters>) => {
        const next = { ...filters, ...changes };
        const query: Record<string, string> = {};
        (Object.keys(DEFAULTS) as Array<keyof SalaryFilters>).forEach((key) => {
            if (next[key] !== DEFAULTS[key]) {
                query[key] = String(next[key]);
            }
        });
        router.get('/insights/comp', query, { preserveState: true, preserveScroll: true, replace: true });
    };

    const filtered = filters.discipline !== DEFAULTS.discipline || filters.level !== 'any' || filters.region !== 'any';

    return (
        <AuthenticatedLayout>
            <Head title="Compensation" />
            <div className="flex flex-col gap-6">
                <PageHeader
                    title="Compensation"
                    subtitle={`What listings with a posted pay range offer, converted to USD. Annual salaries from ${formatUsd(bounds.min_annual_usd)} to ${formatUsd(bounds.max_annual_usd)} count; hourly rates are left out.`}
                />
                <InsightsNav />

                <div className="flex flex-wrap items-center gap-3">
                    <NativeSelect
                        aria-label="Discipline"
                        value={filters.discipline}
                        onChange={(event) => update({ discipline: event.target.value })}
                    >
                        <option value="tech">All tech disciplines</option>
                        {options.disciplines.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                        <option value="all">All listings, incl. non-tech</option>
                    </NativeSelect>
                    <NativeSelect
                        aria-label="Seniority"
                        value={filters.level}
                        onChange={(event) => update({ level: event.target.value })}
                    >
                        <option value="any">Any seniority</option>
                        {options.levels.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </NativeSelect>
                    <NativeSelect
                        aria-label="Region"
                        value={filters.region}
                        onChange={(event) => update({ region: event.target.value })}
                    >
                        <option value="any">Any region</option>
                        {options.regions.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </NativeSelect>
                    <NativeSelect
                        aria-label="Time window"
                        value={String(filters.window)}
                        onChange={(event) => update({ window: Number(event.target.value) as WindowFilter })}
                    >
                        {WINDOWS.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </NativeSelect>
                </div>

                {summary.samples === 0 ? (
                    <EmptyState>
                        {filtered || filters.window !== 0
                            ? 'No salaries match these filters. Try a wider time window or fewer filters.'
                            : 'No listings with a posted annual salary yet. Ranges appear as aggregator sources are scraped.'}
                    </EmptyState>
                ) : (
                    <>
                        <SalaryStats summary={summary} />

                        <div className="grid grid-cols-1 gap-8 xl:grid-cols-2">
                            <section className="flex flex-col gap-3">
                                <SectionLabel>By seniority</SectionLabel>
                                <p className="text-sm text-pretty text-muted-foreground">
                                    Bar spans the middle half of salaries; the tick is the median. Select a level to filter.
                                </p>
                                <RangeBars
                                    rows={by_level}
                                    activeKey={filters.level}
                                    onSelect={(key) => update({ level: key === '' ? 'any' : key })}
                                    unselectableKeys={['unspecified']}
                                    emptyMessage="No salaries for these filters."
                                />
                            </section>
                            <section className="flex flex-col gap-3">
                                <SectionLabel>By region</SectionLabel>
                                <p className="text-sm text-pretty text-muted-foreground">
                                    Where the listing says the role can be done from. Select a region to filter.
                                </p>
                                <RangeBars
                                    rows={by_region}
                                    activeKey={filters.region}
                                    onSelect={(key) => update({ region: key === '' ? 'any' : key })}
                                    unselectableKeys={['unknown']}
                                    emptyMessage="No salaries for these filters."
                                />
                            </section>
                        </div>

                        <section className="flex flex-col gap-3">
                            <SectionLabel>Recent listings</SectionLabel>
                            <RecentSalaries samples={recent} />
                        </section>
                    </>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
