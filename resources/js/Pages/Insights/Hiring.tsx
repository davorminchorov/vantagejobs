import { Head, Link } from '@inertiajs/react';

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import HiringTrendChart from '@/components/charts/HiringTrendChart';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

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
    return (
        <AuthenticatedLayout>
            <Head title="Hiring trends" />
            <div className="space-y-6">
                <header>
                    <h1 className="text-2xl font-semibold tracking-tight">Hiring trends (A-tier)</h1>
                    <p className="text-sm text-muted-foreground">
                        Daily snapshots across active A-tier companies, last 90 days.
                    </p>
                </header>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-sm font-semibold uppercase tracking-wide text-muted-foreground">
                            Global volume
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <HiringTrendChart data={global} height={320} />
                    </CardContent>
                </Card>

                <div className="grid gap-4 lg:grid-cols-2">
                    {companies.map((c) => (
                        <Card key={c.id}>
                            <CardHeader className="flex flex-row items-center justify-between space-y-0">
                                <CardTitle className="text-sm font-semibold">
                                    <Link href={`/companies/${c.id}`} className="hover:underline">
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
            </div>
        </AuthenticatedLayout>
    );
}
