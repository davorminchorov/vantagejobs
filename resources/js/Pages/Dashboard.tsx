import { Head, Link } from '@inertiajs/react';

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';

interface DashboardCompany {
    id: number;
    name: string;
    website: string | null;
    ats_type: string | null;
    tier: 'A' | 'B' | 'C' | 'graveyard';
    status: string;
    last_checked_at: string | null;
}

interface AutoDismissRule {
    display_name: string;
    normalized_company_key: string;
    dismiss_count: number;
}

interface Props {
    companies: DashboardCompany[];
    companies_count: number;
    active_auto_dismiss_rules: AutoDismissRule[];
}

export default function DashboardPage({
    companies = [],
    companies_count = 0,
    active_auto_dismiss_rules = [],
}: Props) {
    return (
        <AuthenticatedLayout>
            <Head title="Dashboard" />
            <div className="mx-auto max-w-6xl px-4 py-8 sm:px-6 lg:px-8">
                <header className="mb-6 flex items-end justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">Dashboard</h1>
                        <p className="text-sm text-muted-foreground">
                            {companies_count > 0
                                ? `${companies_count} ${companies_count === 1 ? 'company' : 'companies'} on the watch list.`
                                : 'No companies on the watch list yet.'}
                        </p>
                    </div>
                </header>

                {active_auto_dismiss_rules.length > 0 && (
                    <AutoDismissBanner rules={active_auto_dismiss_rules} />
                )}

                {companies_count === 0 ? (
                    <EmptyState />
                ) : (
                    <Card>
                        <CardHeader>
                            <CardTitle>Companies</CardTitle>
                            <CardDescription>
                                Seeded watch list. Scrapers and signals arrive in Phase 1+.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="p-0">
                            <ul className="divide-y">
                                {companies.map((company) => (
                                    <li
                                        key={company.id}
                                        className="flex flex-col gap-2 px-6 py-4 sm:flex-row sm:items-center sm:justify-between"
                                    >
                                        <div className="space-y-1">
                                            <div className="flex items-center gap-2">
                                                <span className="font-medium">{company.name}</span>
                                                <span className="rounded bg-secondary px-2 py-0.5 text-xs uppercase text-secondary-foreground">
                                                    Tier {company.tier}
                                                </span>
                                                {company.ats_type && (
                                                    <span className="text-xs text-muted-foreground">
                                                        via {company.ats_type}
                                                    </span>
                                                )}
                                            </div>
                                            {company.website && (
                                                <a
                                                    href={company.website}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    className="text-xs text-muted-foreground hover:text-foreground"
                                                >
                                                    {company.website}
                                                </a>
                                            )}
                                        </div>
                                        <span className="text-xs uppercase tracking-wide text-muted-foreground">
                                            {company.status}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </CardContent>
                    </Card>
                )}
            </div>
        </AuthenticatedLayout>
    );
}

function AutoDismissBanner({ rules }: { rules: AutoDismissRule[] }) {
    const preview = rules.slice(0, 5);
    const remaining = rules.length - preview.length;

    return (
        <div className="mb-6 rounded-md border border-dashed border-muted-foreground/30 bg-muted/30 px-4 py-3 text-sm text-muted-foreground">
            <div className="flex items-baseline justify-between gap-4">
                <p>
                    Auto-dismissing listings from{' '}
                    <span className="font-medium text-foreground">
                        {preview.map((rule) => `${rule.display_name} (${rule.dismiss_count})`).join(', ')}
                    </span>
                    {remaining > 0 && ` and ${remaining} more`}.
                </p>
                <Link
                    href="/discover/sources"
                    className="shrink-0 text-xs uppercase tracking-wide hover:text-foreground"
                >
                    Manage
                </Link>
            </div>
        </div>
    );
}

function EmptyState() {
    return (
        <Card>
            <CardHeader>
                <CardTitle>Welcome to VantageJobs</CardTitle>
                <CardDescription>
                    Phase 0 is shipped. Your watch list is empty.
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
                <p className="text-sm text-muted-foreground">
                    To populate the seeded starter list of 10 companies, run:
                </p>
                <pre className="rounded-md bg-muted p-3 text-xs">
                    spin exec -T php php artisan db:seed --class=ExampleCompaniesSeeder
                </pre>
                <p className="text-sm text-muted-foreground">
                    Scrapers, parsers, and the triage queue arrive in Phase 1+.
                </p>
                <Button asChild variant="outline">
                    <Link href="/settings/profile">Review settings</Link>
                </Button>
            </CardContent>
        </Card>
    );
}
