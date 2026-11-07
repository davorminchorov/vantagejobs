import { Head, Link } from '@inertiajs/react';

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import HiringTrendChart from '@/components/charts/HiringTrendChart';
import InsightsNav from '@/components/insights/InsightsNav';
import PageHeader from '@/components/PageHeader';
import { EmptyState } from '@/components/ui/empty-state';
import { SectionLabel } from '@/components/ui/section-label';

interface TrendPoint {
    date: string;
    open_role_count: number;
    listings_count: number;
}

interface CompanyTrend {
    id: number;
    name: string;
    series: TrendPoint[];
}

interface Props {
    global: TrendPoint[];
    companies: CompanyTrend[];
}

export default function HiringInsightsPage({ global, companies }: Props) {
    const noTrendData = global.length === 0 && companies.every((c) => c.series.length === 0);

    return (
        <AuthenticatedLayout>
            <Head title="Hiring trends" />
            <div className="flex flex-col gap-6">
                <PageHeader
                    title="Hiring trends (A-tier)"
                    subtitle="Daily snapshots across active A-tier companies, last 90 days."
                />

                <InsightsNav />

                {noTrendData ? (
                    <EmptyState>No hiring trend data yet for tracked A-tier companies.</EmptyState>
                ) : (
                    <>
                        <section className="flex flex-col gap-3">
                            <SectionLabel>Global volume</SectionLabel>
                            <HiringTrendChart data={global} height={320} />
                        </section>

                        <ul role="list" className="grid gap-x-8 gap-y-6 border-t border-border pt-6 lg:grid-cols-2">
                            {companies.map((c) => (
                                <li key={c.id} className="flex min-w-0 flex-col gap-3">
                                    <Link
                                        href={`/companies/${c.id}`}
                                        className="truncate text-base font-medium text-primary hover:underline sm:text-sm"
                                    >
                                        {c.name}
                                    </Link>
                                    <HiringTrendChart data={c.series} showListings={false} height={140} />
                                </li>
                            ))}
                        </ul>
                    </>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
