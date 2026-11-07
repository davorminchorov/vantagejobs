import { Head, router } from '@inertiajs/react';

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { FundedCompanyRow } from '@/components/funded/FundedCompanyRow';
import type { FundedCompanyGroup, RecentlyFundedFilters, RegionFilter, RoundFilter, SoftwareFilter } from '@/components/funded/types';
import InsightsNav from '@/components/insights/InsightsNav';
import PageHeader from '@/components/PageHeader';
import { EmptyState } from '@/components/ui/empty-state';
import { NativeSelect } from '@/components/ui/native-select';

interface Props {
    filters: RecentlyFundedFilters;
    groups: FundedCompanyGroup[];
    max_age_days: number;
}

const REGIONS: ReadonlyArray<{ value: RegionFilter; label: string }> = [
    { value: 'all', label: 'All regions' },
    { value: 'us', label: 'US' },
    { value: 'europe', label: 'Europe (incl. UK)' },
    { value: 'uk', label: 'UK' },
    { value: 'global', label: 'Global sources' },
];

const ROUNDS: ReadonlyArray<{ value: RoundFilter; label: string }> = [
    { value: 'all', label: 'All rounds' },
    { value: 'seed', label: 'Pre-seed and seed' },
    { value: 'early', label: 'Series A and B' },
    { value: 'growth', label: 'Series C and later' },
];

const SOFTWARE: ReadonlyArray<{ value: SoftwareFilter; label: string }> = [
    { value: 'all', label: 'Any company type' },
    { value: 'yes', label: 'Software' },
    { value: 'no', label: 'Hardware / deep tech' },
];

export default function RecentlyFundedPage({ filters, groups, max_age_days }: Props) {
    const update = (changes: Partial<RecentlyFundedFilters>) => {
        const next = { ...filters, ...changes };
        const query: Record<string, string> = {};
        if (next.region !== 'all') query.region = next.region;
        if (next.round !== 'all') query.round = next.round;
        if (next.tracked !== 'hide') query.tracked = next.tracked;
        if (next.software !== 'all') query.software = next.software;
        router.get('/insights/recently-funded', query, { preserveState: true, preserveScroll: true, replace: true });
    };

    const dismiss = (group: FundedCompanyGroup) => {
        router.post(`/insights/recently-funded/${group.announcement_id}/dismiss`, {}, { preserveScroll: true });
    };

    const filtered = filters.region !== 'all' || filters.round !== 'all' || filters.software !== 'all';

    return (
        <AuthenticatedLayout>
            <Head title="Recently funded" />
            <div className="flex flex-col gap-6">
                <PageHeader
                    title="Recently funded"
                    subtitle={`Companies that announced a round in startup news over the last ${max_age_days} days. A company that just raised often starts hiring a few months later.`}
                />
                <InsightsNav />

                <div className="flex flex-wrap items-center gap-3">
                    <NativeSelect
                        aria-label="Region"
                        value={filters.region}
                        onChange={(event) => update({ region: event.target.value as RegionFilter })}
                    >
                        {REGIONS.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </NativeSelect>
                    <NativeSelect
                        aria-label="Round"
                        value={filters.round}
                        onChange={(event) => update({ round: event.target.value as RoundFilter })}
                    >
                        {ROUNDS.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </NativeSelect>
                    <NativeSelect
                        aria-label="Company type"
                        value={filters.software}
                        onChange={(event) => update({ software: event.target.value as SoftwareFilter })}
                    >
                        {SOFTWARE.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </NativeSelect>
                    <label className="flex items-center gap-2 text-base sm:text-sm">
                        <input
                            type="checkbox"
                            checked={filters.tracked === 'hide'}
                            onChange={(event) => update({ tracked: event.target.checked ? 'hide' : 'show' })}
                            className="size-4 rounded border-input accent-primary"
                        />
                        Hide companies I track
                    </label>
                    <span className="text-base text-muted-foreground tabular-nums sm:ml-auto sm:text-sm">
                        {groups.length} {groups.length === 1 ? 'company' : 'companies'}
                    </span>
                </div>

                {groups.length === 0 ? (
                    <EmptyState>
                        {filtered
                            ? 'No companies match these filters. Most headlines do not name the round, and some companies have no clear type, so try widening them.'
                            : 'Nothing new. The feeds are read every morning.'}
                    </EmptyState>
                ) : (
                    <ul role="list" className="divide-y divide-border border-y border-border">
                        {groups.map((group) => (
                            <FundedCompanyRow key={group.key} group={group} onDismiss={() => dismiss(group)} />
                        ))}
                    </ul>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
