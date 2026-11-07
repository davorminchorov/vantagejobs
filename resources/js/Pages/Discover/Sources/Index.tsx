import { Head, router } from '@inertiajs/react';

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Button } from '@/components/ui/button';
import PageHeader from '@/components/PageHeader';
import { Badge } from '@/components/ui/badge';
import { RelativeDate } from '@/lib/dates';

interface Source {
    id: number;
    type: string;
    name: string;
    enabled: boolean;
    cadence_minutes: number;
    last_run_at: string | null;
    next_run_at: string | null;
    last_error: string | null;
    consecutive_failures: number;
}

interface Rule {
    id: number;
    normalized_company_key: string;
    display_name: string;
    dismiss_count: number;
    auto_dismiss: boolean;
    last_dismissed_at: string | null;
}

interface Props {
    sources: Source[];
    rules: Rule[];
}

export default function SourcesIndex({ sources, rules }: Props) {
    function toggle(id: number) {
        router.post(`/discover/sources/${id}/toggle`, {}, {
            preserveScroll: true,
            preserveState: true,
            only: ['sources'],
        });
    }

    function runNow(id: number) {
        router.post(`/discover/sources/${id}/run`, {}, {
            preserveScroll: true,
            preserveState: true,
            only: ['sources', 'flash'],
        });
    }

    function revoke(id: number) {
        router.post(`/discover/auto-dismiss-rules/${id}/revoke`, {}, {
            preserveScroll: true,
            preserveState: true,
            only: ['rules'],
        });
    }

    const activeRules = rules.filter((r) => r.auto_dismiss);
    const inactiveRules = rules.filter((r) => ! r.auto_dismiss);

    return (
        <AuthenticatedLayout>
            <Head title="Discovery sources" />
            <div className="flex flex-col gap-10">
                <PageHeader
                    title="Discovery sources"
                    subtitle="Enable or disable sources, run them on demand, and review the active auto-dismiss rules."
                />

                <section aria-labelledby="sources-heading" className="flex flex-col gap-3">
                    <h2 id="sources-heading" className="text-lg font-semibold text-balance">
                        Aggregator sources
                    </h2>
                    <ul role="list" className="divide-y divide-border border-y border-border">
                        {sources.map((source) => (
                            <li key={source.id} className="flex flex-col gap-3 py-4 sm:flex-row sm:items-center sm:justify-between">
                                <div className="flex min-w-0 flex-col gap-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="font-medium text-foreground">{source.name}</span>
                                        <Badge variant={source.enabled ? 'success' : 'secondary'}>
                                            {source.enabled ? 'Enabled' : 'Disabled'}
                                        </Badge>
                                        {source.consecutive_failures > 0 && (
                                            <Badge variant="warning">
                                                <span className="tabular-nums">{source.consecutive_failures}</span> consecutive failures
                                            </Badge>
                                        )}
                                    </div>
                                    <p className="text-sm text-pretty text-muted-foreground">
                                        Cadence: every <span className="tabular-nums">{Math.round(source.cadence_minutes / 60)}</span>h ·
                                        Last run:{' '}
                                        {source.last_run_at ? (
                                            <RelativeDate iso={source.last_run_at} className="tabular-nums" />
                                        ) : (
                                            <span className="tabular-nums">never</span>
                                        )}{' '}
                                        · Next:{' '}
                                        {source.next_run_at ? (
                                            <RelativeDate iso={source.next_run_at} className="tabular-nums" />
                                        ) : (
                                            <span className="tabular-nums">—</span>
                                        )}
                                    </p>
                                    {source.last_error && (
                                        <p className="text-sm text-pretty text-amber-700 dark:text-amber-300">{source.last_error}</p>
                                    )}
                                </div>
                                <div className="flex shrink-0 gap-2">
                                    <Button size="sm" variant="outline" onClick={() => toggle(source.id)}>
                                        {source.enabled ? 'Disable' : 'Enable'}
                                    </Button>
                                    <Button size="sm" variant="outline" onClick={() => runNow(source.id)}>
                                        Run now
                                    </Button>
                                </div>
                            </li>
                        ))}
                    </ul>
                </section>

                <section aria-labelledby="rules-heading" className="flex flex-col gap-3">
                    <h2 id="rules-heading" className="text-lg font-semibold text-balance">
                        Auto-dismiss rules
                    </h2>
                    {activeRules.length === 0 ? (
                        <p className="text-base text-pretty text-muted-foreground sm:text-sm">
                            No employers are auto-dismissed right now. Rules arm after 3 manual dismissals of the same employer.
                        </p>
                    ) : (
                        <ul role="list" className="divide-y divide-border border-y border-border">
                            {activeRules.map((rule) => (
                                <li key={rule.id} className="flex items-center justify-between gap-3 py-3">
                                    <div className="flex min-w-0 flex-wrap items-baseline gap-x-2 text-base sm:text-sm">
                                        <span className="font-medium text-foreground">{rule.display_name}</span>
                                        <span className="text-sm text-muted-foreground">
                                            <span className="tabular-nums">{rule.dismiss_count}</span> dismissals
                                            {rule.last_dismissed_at && (
                                                <>
                                                    {' · last '}
                                                    <RelativeDate iso={rule.last_dismissed_at} className="tabular-nums" />
                                                </>
                                            )}
                                        </span>
                                    </div>
                                    <Button size="sm" variant="ghost" className="shrink-0" onClick={() => revoke(rule.id)}>
                                        Revoke
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    )}
                    {inactiveRules.length > 0 && (
                        <p className="text-sm text-pretty text-muted-foreground">
                            <span className="tabular-nums">{inactiveRules.length}</span> {inactiveRules.length === 1 ? 'employer has' : 'employers have'} dismissals on file but
                            no active rule yet.
                        </p>
                    )}
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
