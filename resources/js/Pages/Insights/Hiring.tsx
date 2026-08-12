import { Head, Link } from '@inertiajs/react';

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import HiringTrendChart from '@/components/charts/HiringTrendChart';
import InsightsNav from '@/components/insights/InsightsNav';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';

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
            <div className="space-y-6">
                <header>
                    <h1 className="text-2xl font-semibold tracking-tight text-foreground">
                        Hiring trends (A-tier)
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Daily snapshots across active A-tier companies, last 90 days.
                    </p>
                </header>

                <InsightsNav />

                {noTrendData ? (
                    <EmptyState>No hiring trend data yet for tracked A-tier companies.</EmptyState>
                ) : (
                    <>
                        <Card className="rounded-2xl border border-border bg-card shadow-sm">
                            <CardHeader>
                                <CardTitle className="font-mono text-sm font-medium uppercase tracking-wide text-muted-foreground">
                                    Global volume
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <HiringTrendChart data={global} height={320} />
                            </CardContent>
                        </Card>

                        <div className="grid gap-4 lg:grid-cols-2">
                            {companies.map((c) => (
                                <Card
                                    key={c.id}
                                    className="rounded-2xl border border-border bg-card shadow-sm transition-all hover:-translate-y-0.5 hover:shadow-md"
                                >
                                    <CardHeader className="flex flex-row items-center justify-between space-y-0">
                                        <CardTitle className="text-sm font-medium">
                                            <Link
                                                href={`/companies/${c.id}`}
                                                className="text-teal-700 hover:underline dark:text-teal-400"
                                            >
                                                {c.name}
                                            </Link>
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        <HiringTrendChart data={c.series} showListings={false} height={140} />
                                    </CardContent>
                                </Card>
                            ))}
                        </div>
                    </>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
