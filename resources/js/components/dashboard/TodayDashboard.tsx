import { Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import type { ReactNode } from 'react';

import DashboardEmptyState from '@/components/dashboard/DashboardEmptyState';
import {
    type DashboardProps,
    pluralize,
    tierVariant,
} from '@/components/dashboard/types';
import { Badge } from '@/components/ui/badge';
import { RelativeDate } from '@/lib/dates';

interface NeedItem {
    key: string;
    href: string;
    tone: 'overdue' | 'info' | 'warning' | 'ok';
    primary: ReactNode;
    secondary?: ReactNode;
    action: string;
}

const dotTone: Record<NeedItem['tone'], string> = {
    overdue: 'bg-rose-500',
    warning: 'bg-amber-500',
    info: 'bg-primary',
    ok: 'bg-emerald-500',
};

export default function TodayDashboard({
    companies = [],
    companies_count = 0,
    active_auto_dismiss_rules = [],
    source_health,
    applications_attention,
    pipeline_summary,
    discover_new_count = 0,
}: DashboardProps) {
    const activeStages = pipeline_summary.stages.filter((stage) => !stage.terminal);
    const attentionSources = (source_health?.sources ?? []).filter((source) => source.status !== 'green');

    const summaryParts = [
        `${pluralize(applications_attention.count, 'application', 'applications')} needing attention`,
        `${pluralize(discover_new_count, 'new listing', 'new listings')}`,
        `${pluralize(pipeline_summary.total_active, 'active application', 'active applications')}`,
    ];

    const needs: NeedItem[] = [];

    applications_attention.top.forEach((row) => {
        needs.push({
            key: `application-${row.role_id}`,
            href: row.url,
            tone: 'overdue',
            primary: (
                <>
                    <span className="font-medium text-foreground">{row.title}</span>
                    {row.company_name && <span className="text-muted-foreground"> at {row.company_name}</span>}
                </>
            ),
            secondary: row.reason_label,
            action: 'Open',
        });
    });

    if (applications_attention.count > applications_attention.top.length) {
        needs.push({
            key: 'applications-more',
            href: '/applications?view=attention',
            tone: 'overdue',
            primary: (
                <span className="text-muted-foreground">
                    {applications_attention.count - applications_attention.top.length} more applications need attention.
                </span>
            ),
            action: 'View all',
        });
    }

    if (discover_new_count > 0) {
        needs.push({
            key: 'discover',
            href: '/discover',
            tone: 'info',
            primary: (
                <span className="font-medium text-foreground">
                    {pluralize(discover_new_count, 'new listing', 'new listings')} in Discover
                </span>
            ),
            secondary: 'Waiting for review.',
            action: 'Review',
        });
    }

    attentionSources.slice(0, 4).forEach((source) => {
        needs.push({
            key: `source-${source.value}`,
            href: source.kind === 'ats' ? '/admin/runs' : '/discover/sources',
            tone: source.status === 'red' ? 'overdue' : 'warning',
            primary: <span className="font-medium text-foreground">{source.name}</span>,
            secondary: source.reasons.join('; '),
            action: 'Check',
        });
    });

    if (source_health?.status === 'green' && source_health.total > 0) {
        needs.push({
            key: 'sources-healthy',
            href: '/discover/sources',
            tone: 'ok',
            primary: (
                <span className="text-muted-foreground">
                    All {pluralize(source_health.total, 'source', 'sources')} healthy.
                </span>
            ),
            action: 'View sources',
        });
    }

    if (active_auto_dismiss_rules.length > 0) {
        const shown = active_auto_dismiss_rules
            .slice(0, 5)
            .map((rule) => `${rule.display_name} (${rule.dismiss_count})`)
            .join(', ');
        const extra =
            active_auto_dismiss_rules.length > 5 ? ` and ${active_auto_dismiss_rules.length - 5} more` : '';

        needs.push({
            key: 'auto-dismiss',
            href: '/discover/sources',
            tone: 'warning',
            primary: <span className="font-medium text-foreground">Auto-dismissing listings</span>,
            secondary: `From ${shown}${extra}.`,
            action: 'Manage',
        });
    }

    return (
        <div className="mx-auto w-full max-w-3xl text-foreground">
            <header>
                <h1 className="text-2xl font-semibold tracking-tight text-balance">Today</h1>
                {companies_count > 0 && (
                    <p className="mt-2 text-base text-pretty text-muted-foreground sm:text-sm">
                        {summaryParts.join(', ')}.
                    </p>
                )}
            </header>

            {companies_count === 0 ? (
                <DashboardEmptyState />
            ) : (
                <div className="mt-10 flex flex-col gap-12">
                    <section aria-labelledby="needs-you-heading">
                        <h2 id="needs-you-heading" className="text-sm font-semibold text-foreground">
                            Needs you
                        </h2>
                        {needs.every((item) => item.tone === 'ok') && (
                            <p className="mt-4 text-base text-pretty text-muted-foreground sm:text-sm">
                                Nothing needs you right now.
                            </p>
                        )}
                        {needs.length > 0 && (
                            <ul role="list" className="mt-2 divide-y divide-border border-y border-border">
                                {needs.map((item) => (
                                    <li key={item.key}>
                                        <Link
                                            href={item.href}
                                            className="flex items-center gap-3 px-2 py-3 text-base hover:bg-accent sm:text-sm"
                                        >
                                            <span
                                                className={`size-2 shrink-0 rounded-full ${dotTone[item.tone]}`}
                                                aria-hidden="true"
                                            />
                                            <span className="min-w-0 flex-1">
                                                <span className="block truncate">{item.primary}</span>
                                                {item.secondary && (
                                                    <span className="block truncate text-muted-foreground">
                                                        {item.secondary}
                                                    </span>
                                                )}
                                            </span>
                                            <span className="flex shrink-0 items-center gap-1 text-primary">
                                                {item.action}
                                                <ArrowRight className="size-4 shrink-0" aria-hidden="true" />
                                            </span>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>

                    <section aria-labelledby="pipeline-heading">
                        <div className="flex items-baseline justify-between gap-3">
                            <h2 id="pipeline-heading" className="text-sm font-semibold text-foreground">
                                Applications
                            </h2>
                            <Link href="/applications" className="text-sm text-primary hover:underline">
                                Open applications
                            </Link>
                        </div>
                        {activeStages.every((stage) => stage.count === 0) ? (
                            <p className="mt-4 text-base text-pretty text-muted-foreground sm:text-sm">
                                No active applications.
                            </p>
                        ) : (
                            <ul
                                role="list"
                                className="mt-4 flex flex-wrap gap-x-8 gap-y-4"
                            >
                                {activeStages.map((stage) => (
                                    <li key={stage.status} className="min-w-0">
                                        <div className="text-2xl font-semibold tabular-nums">{stage.count}</div>
                                        <div className="truncate text-sm text-muted-foreground">{stage.label}</div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>

                    <section aria-labelledby="watch-heading">
                        <div className="flex items-baseline justify-between gap-3">
                            <h2 id="watch-heading" className="text-sm font-semibold text-foreground">
                                Watch list
                            </h2>
                            <Link href="/companies" className="text-sm text-primary hover:underline">
                                View all
                            </Link>
                        </div>
                        <ul role="list" className="mt-2 divide-y divide-border border-y border-border">
                            {companies.slice(0, 8).map((company) => (
                                <li key={company.id}>
                                    <Link
                                        href={`/companies/${company.id}`}
                                        className="flex items-center gap-3 px-2 py-3 text-base hover:bg-accent sm:text-sm"
                                    >
                                        <span className="min-w-0 flex-1 truncate font-medium">{company.name}</span>
                                        <Badge variant={tierVariant[company.tier] ?? 'secondary'}>
                                            Tier {company.tier}
                                        </Badge>
                                        <span className="w-20 shrink-0 text-right tabular-nums text-muted-foreground">
                                            {pluralize(company.open_roles, 'role', 'roles')}
                                        </span>
                                        <span className="hidden w-28 shrink-0 text-right text-muted-foreground sm:block">
                                            <RelativeDate iso={company.last_checked_at} />
                                        </span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </section>
                </div>
            )}
        </div>
    );
}
