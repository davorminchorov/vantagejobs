import { Head, Link } from '@inertiajs/react';

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';

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

const tierPill: Record<string, string> = {
    A: 'bg-emerald-100 text-emerald-700',
    B: 'bg-amber-100 text-amber-700',
    C: 'bg-sky-100 text-sky-700',
    graveyard: 'bg-rose-100 text-rose-700',
};

export default function DashboardPage({
    companies = [],
    companies_count = 0,
    active_auto_dismiss_rules = [],
}: Props) {
    return (
        <AuthenticatedLayout>
            <Head title="Dashboard" />

            <div className="-mx-4 -my-6 min-h-[calc(100svh-4rem)] bg-gradient-to-b from-teal-50 via-white to-white px-4 py-8 text-zinc-900 sm:-mx-6 sm:px-6">
                <div className="mx-auto max-w-6xl">
                    <header className="mb-8 flex flex-wrap items-end justify-between gap-4">
                        <div>
                            <h1 className="bg-gradient-to-r from-teal-600 to-emerald-500 bg-clip-text text-3xl font-semibold tracking-tight text-transparent">
                                Dashboard
                            </h1>
                            <p className="mt-1 text-sm text-zinc-500">
                                {companies_count > 0
                                    ? `${companies_count} ${companies_count === 1 ? 'company' : 'companies'} on the watch list.`
                                    : 'No companies on the watch list yet.'}
                            </p>
                        </div>
                        <span className="rounded-full bg-teal-600 px-4 py-1.5 text-sm font-semibold text-white shadow-lg shadow-teal-600/25">
                            <span className="font-mono tabular-nums">{companies_count}</span> tracked
                        </span>
                    </header>

                    {active_auto_dismiss_rules.length > 0 && (
                        <div className="mb-8 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-amber-200/70 bg-amber-50 px-5 py-4 text-sm text-amber-900 shadow-sm">
                            <p>
                                <span className="font-semibold">Auto-dismissing</span> listings from{' '}
                                {active_auto_dismiss_rules
                                    .slice(0, 5)
                                    .map((r) => `${r.display_name} (${r.dismiss_count})`)
                                    .join(', ')}
                                {active_auto_dismiss_rules.length > 5 &&
                                    ` and ${active_auto_dismiss_rules.length - 5} more`}
                                .
                            </p>
                            <Link
                                href="/discover/sources"
                                className="shrink-0 rounded-full bg-white px-4 py-1.5 text-xs font-semibold text-amber-700 shadow-sm hover:bg-amber-100"
                            >
                                Manage
                            </Link>
                        </div>
                    )}

                    {companies_count === 0 ? (
                        <div className="rounded-3xl border border-zinc-200 bg-white p-8 shadow-xl shadow-zinc-200/50">
                            <h2 className="text-xl font-semibold tracking-tight">Welcome to VantageJobs</h2>
                            <p className="mt-2 text-sm text-zinc-500">
                                Phase 0 is shipped. To populate the seeded starter list of 10 companies, run:
                            </p>
                            <pre className="mt-4 overflow-x-auto rounded-xl bg-zinc-900 p-4 font-mono text-xs text-zinc-100">
                                spin exec -T php php artisan db:seed --class=ExampleCompaniesSeeder
                            </pre>
                            <Link
                                href="/settings/profile"
                                className="mt-6 inline-block rounded-full bg-teal-600 px-6 py-2.5 text-sm font-semibold text-white shadow-lg shadow-teal-600/25 transition-transform hover:-translate-y-0.5"
                            >
                                Review settings
                            </Link>
                        </div>
                    ) : (
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            {companies.map((company) => (
                                <div
                                    key={company.id}
                                    className="group rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm transition-all hover:-translate-y-1 hover:shadow-xl hover:shadow-teal-200/40"
                                >
                                    <div className="mb-3 flex items-start justify-between gap-2">
                                        <span className="font-semibold text-zinc-900">{company.name}</span>
                                        <span
                                            className={`rounded-full px-2.5 py-0.5 font-mono text-xs font-semibold ${tierPill[company.tier] ?? tierPill.C}`}
                                        >
                                            Tier {company.tier}
                                        </span>
                                    </div>
                                    {company.website && (
                                        <a
                                            href={company.website}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="block truncate text-sm text-zinc-400 hover:text-teal-700"
                                        >
                                            {company.website}
                                        </a>
                                    )}
                                    <div className="mt-4 flex items-center justify-between border-t border-zinc-100 pt-3">
                                        <span className="text-xs font-medium uppercase tracking-wide text-zinc-400">
                                            {company.status}
                                        </span>
                                        {company.ats_type && (
                                            <span className="rounded-md bg-zinc-100 px-2 py-0.5 text-xs text-zinc-500">
                                                {company.ats_type}
                                            </span>
                                        )}
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
