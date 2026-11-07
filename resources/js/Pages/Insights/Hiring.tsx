import { Head, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import HiringTrendChart from '@/components/charts/HiringTrendChart';
import { HiringStats } from '@/components/hiring/HiringStats';
import { MomentumTable } from '@/components/hiring/MomentumTable';
import { sortCompanies } from '@/components/hiring/helpers';
import type { CompanyMomentum, HiringPoint, HiringSummary, SortState, TierFilter } from '@/components/hiring/types';
import InsightsNav from '@/components/insights/InsightsNav';
import PageHeader from '@/components/PageHeader';
import { EmptyState } from '@/components/ui/empty-state';
import { NativeSelect } from '@/components/ui/native-select';
import { SectionLabel } from '@/components/ui/section-label';

interface Props {
    filters: { tier: TierFilter };
    summary: HiringSummary;
    series: HiringPoint[];
    companies: CompanyMomentum[];
}

const TIER_OPTIONS: ReadonlyArray<{ value: TierFilter; label: string }> = [
    { value: 'all', label: 'All tiers' },
    { value: 'A', label: 'Tier A' },
    { value: 'B', label: 'Tier B' },
    { value: 'C', label: 'Tier C' },
];

export default function HiringInsightsPage({ filters, summary, series, companies }: Props) {
    const [sort, setSort] = useState<SortState>({ key: 'change_30d', direction: 'desc' });
    const sorted = useMemo(() => sortCompanies(companies, sort), [companies, sort]);

    const changeTier = (tier: TierFilter) => {
        router.get('/insights/hiring', tier === 'all' ? {} : { tier }, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    return (
        <AuthenticatedLayout>
            <Head title="Hiring trends" />
            <div className="flex flex-col gap-6">
                <PageHeader
                    title="Hiring trends"
                    subtitle="Who is ramping up and who is slowing down, from daily open-role snapshots."
                    actions={
                        <NativeSelect
                            aria-label="Tier"
                            value={filters.tier}
                            onChange={(event) => changeTier(event.target.value as TierFilter)}
                        >
                            {TIER_OPTIONS.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </NativeSelect>
                    }
                />

                <InsightsNav />

                {companies.length === 0 ? (
                    <EmptyState>No active companies in this tier.</EmptyState>
                ) : (
                    <>
                        <HiringStats summary={summary} />

                        <section className="flex flex-col gap-3">
                            <SectionLabel>Open roles, last 90 days</SectionLabel>
                            <HiringTrendChart data={series} showListings={false} showLegend={false} curve="step" height={240} />
                        </section>

                        <section className="flex flex-col gap-3">
                            <SectionLabel>Companies</SectionLabel>
                            <MomentumTable companies={sorted} sort={sort} onSort={setSort} />
                        </section>
                    </>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
