import { Head, Link } from '@inertiajs/react';
import type { ReactNode } from 'react';

import PageHeader from '@/components/PageHeader';
import SourceHealthTile, { type SourceHealthSummary } from '@/components/dashboard/SourceHealthTile';
import { Badge } from '@/components/ui/badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { RelativeDate } from '@/lib/dates';

interface WatchListCompany {
    id: number;
    name: string;
    tier: 'A' | 'B' | 'C' | 'graveyard';
    status: string;
    open_roles: number;
    last_checked_at: string | null;
}

interface AutoDismissRule {
    display_name: string;
    normalized_company_key: string;
    dismiss_count: number;
}

interface FollowUpDue {
    contact_name: string;
    company_name: string | null;
    days_overdue: number | null;
}

interface FollowUpsDue {
    count: number;
    top: FollowUpDue[];
}

interface PipelineStage {
    status: string;
    label: string;
    count: number;
}

interface PipelineSummary {
    total_active: number;
    stages: PipelineStage[];
}

interface Props {
    companies: WatchListCompany[];
    companies_count: number;
    active_auto_dismiss_rules: AutoDismissRule[];
    source_health?: SourceHealthSummary;
    follow_ups_due: FollowUpsDue;
    pipeline_summary: PipelineSummary;
    discover_new_count: number;
}

const tierVariant: Record<string, 'success' | 'warning' | 'secondary' | 'destructive'> = {
    A: 'success',
    B: 'warning',
    C: 'secondary',
    graveyard: 'destructive',
};

const TERMINAL_STAGES = new Set(['rejected', 'withdrawn']);

export default function DashboardPage({
    companies = [],
    companies_count = 0,
    active_auto_dismiss_rules = [],
    source_health,
    follow_ups_due,
    pipeline_summary,
    discover_new_count = 0,
}: Props) {
    const activeStages = pipeline_summary.stages.filter((stage) => !TERMINAL_STAGES.has(stage.status));

    return (
        <AuthenticatedLayout>
            <Head title="Dashboard" />

            <div className="text-foreground">
                <PageHeader
                    title="Dashboard"
                    subtitle={`${companies_count} ${companies_count === 1 ? 'company' : 'companies'} on the watch list`}
                />

                <div className="mt-8">
                    {source_health && <SourceHealthTile summary={source_health} />}

                    {active_auto_dismiss_rules.length > 0 && (
                        <div className="mb-8 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-amber-200/70 bg-amber-50 px-5 py-4 text-sm text-amber-900 shadow-sm dark:border-amber-700/40 dark:bg-amber-900/25 dark:text-amber-100">
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
                                className="shrink-0 rounded-full bg-background px-4 py-1.5 text-xs font-semibold text-amber-700 shadow-sm hover:bg-amber-100 dark:bg-amber-950/40 dark:text-amber-200 dark:hover:bg-amber-900/60"
                            >
                                Manage
                            </Link>
                        </div>
                    )}

                    {companies_count === 0 ? (
                        <div className="rounded-3xl border border-border bg-card p-8 shadow-xl shadow-black/5">
                            <h2 className="text-xl font-semibold tracking-tight">Welcome to VantageJobs</h2>
                            <p className="mt-2 text-sm text-muted-foreground">
                                Add a company to start tracking its roles, or bulk import a list to get going
                                faster.
                            </p>
                            <div className="mt-6 flex flex-wrap items-center gap-3">
                                <Link
                                    href="/companies/create"
                                    className="rounded-full bg-teal-600 px-6 py-2.5 text-sm font-semibold text-white shadow-lg shadow-teal-600/25 transition-transform hover:-translate-y-0.5"
                                >
                                    Add company
                                </Link>
                                <Link
                                    href="/import"
                                    className="rounded-full border border-input px-6 py-2.5 text-sm font-semibold text-foreground shadow-sm transition-transform hover:-translate-y-0.5 hover:bg-accent"
                                >
                                    Bulk import
                                </Link>
                                <Link
                                    href="/settings/profile"
                                    className="text-sm font-semibold text-teal-700 hover:underline dark:text-teal-300"
                                >
                                    Review settings
                                </Link>
                            </div>
                        </div>
                    ) : (
                        <>
                            <div className="mb-8 grid gap-4 sm:grid-cols-3">
                                <NeedsYouBlock title="Follow-ups due" count={follow_ups_due.count} href="/follow-ups">
                                    {follow_ups_due.top.length === 0 ? (
                                        <p className="text-sm text-muted-foreground">Nothing due right now.</p>
                                    ) : (
                                        <ul className="space-y-2">
                                            {follow_ups_due.top.map((due, index) => (
                                                <li key={index} className="text-sm">
                                                    <span className="font-medium text-foreground">
                                                        {due.contact_name}
                                                    </span>
                                                    {due.company_name && (
                                                        <span className="text-muted-foreground">
                                                            {' '}
                                                            · {due.company_name}
                                                        </span>
                                                    )}
                                                    <span className="text-muted-foreground">
                                                        {' '}
                                                        ·{' '}
                                                        {due.days_overdue === null
                                                            ? 'never contacted'
                                                            : `${due.days_overdue}d overdue`}
                                                    </span>
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </NeedsYouBlock>

                                <NeedsYouBlock
                                    title="Pipeline"
                                    count={pipeline_summary.total_active}
                                    href="/pipeline"
                                >
                                    {activeStages.every((stage) => stage.count === 0) ? (
                                        <p className="text-sm text-muted-foreground">No active applications.</p>
                                    ) : (
                                        <ul className="space-y-1.5">
                                            {activeStages
                                                .filter((stage) => stage.count > 0)
                                                .map((stage) => (
                                                    <li
                                                        key={stage.status}
                                                        className="flex items-center justify-between text-sm"
                                                    >
                                                        <span className="text-muted-foreground">{stage.label}</span>
                                                        <span className="font-mono tabular-nums text-foreground">
                                                            {stage.count}
                                                        </span>
                                                    </li>
                                                ))}
                                        </ul>
                                    )}
                                </NeedsYouBlock>

                                <NeedsYouBlock
                                    title="New in Discover"
                                    count={discover_new_count}
                                    href="/discover"
                                    linkLabel="Review"
                                >
                                    <p className="text-sm text-muted-foreground">
                                        {discover_new_count === 0
                                            ? 'Nothing new since your last check.'
                                            : `${discover_new_count} ${discover_new_count === 1 ? 'listing' : 'listings'} waiting for review.`}
                                    </p>
                                </NeedsYouBlock>
                            </div>

                            <div className="rounded-2xl border border-border bg-card shadow-sm">
                                <div className="flex items-center justify-between px-5 py-4">
                                    <h2 className="text-sm font-semibold text-foreground">Watch list</h2>
                                    <Link
                                        href="/companies"
                                        className="text-xs font-semibold text-teal-700 hover:underline dark:text-teal-300"
                                    >
                                        View all
                                    </Link>
                                </div>
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Name</TableHead>
                                            <TableHead>Tier</TableHead>
                                            <TableHead>Open roles</TableHead>
                                            <TableHead>Last checked</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {companies.map((company) => (
                                            <TableRow key={company.id}>
                                                <TableCell>
                                                    <Link
                                                        href={`/companies/${company.id}`}
                                                        className="font-medium text-foreground hover:underline"
                                                    >
                                                        {company.name}
                                                    </Link>
                                                </TableCell>
                                                <TableCell>
                                                    <Badge variant={tierVariant[company.tier] ?? 'secondary'}>
                                                        Tier {company.tier}
                                                    </Badge>
                                                </TableCell>
                                                <TableCell className="font-mono tabular-nums text-muted-foreground">
                                                    {company.open_roles}
                                                </TableCell>
                                                <TableCell className="text-muted-foreground">
                                                    <RelativeDate iso={company.last_checked_at} />
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        </>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

interface NeedsYouBlockProps {
    title: string;
    count: number;
    href: string;
    linkLabel?: string;
    children: ReactNode;
}

function NeedsYouBlock({ title, count, href, linkLabel = 'View all', children }: NeedsYouBlockProps) {
    return (
        <div className="flex flex-col rounded-2xl border border-border bg-card p-5 shadow-sm">
            <div className="mb-3 flex items-center justify-between gap-2">
                <h2 className="text-sm font-semibold text-foreground">{title}</h2>
                <span className="font-mono text-lg tabular-nums text-foreground">{count}</span>
            </div>
            <div className="flex-1">{children}</div>
            <Link
                href={href}
                className="mt-4 text-xs font-semibold text-teal-700 hover:underline dark:text-teal-300"
            >
                {linkLabel}
            </Link>
        </div>
    );
}
