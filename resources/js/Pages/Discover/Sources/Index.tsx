import { Head, router } from '@inertiajs/react';

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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
            <div className="mx-auto max-w-6xl space-y-8 px-4 py-8 sm:px-6 lg:px-8">
                <header>
                    <h1 className="text-2xl font-semibold tracking-tight">Discovery sources</h1>
                    <p className="text-sm text-muted-foreground">
                        Enable / disable, run on demand, and review the active auto-dismiss rules.
                    </p>
                </header>

                <Card className="rounded-2xl border border-border bg-card shadow-sm">
                    <CardHeader>
                        <CardTitle>Aggregator sources</CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        <ul className="divide-y divide-border">
                            {sources.map((source) => (
                                <li key={source.id} className="flex flex-col gap-3 px-6 py-4 sm:flex-row sm:items-center sm:justify-between">
                                    <div className="space-y-1">
                                        <div className="flex items-center gap-2">
                                            <span className="font-medium text-foreground">{source.name}</span>
                                            <span
                                                className={
                                                    'rounded-full px-2.5 py-0.5 font-mono text-xs uppercase tracking-wide ' +
                                                    (source.enabled
                                                        ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-200'
                                                        : 'bg-muted text-muted-foreground')
                                                }
                                            >
                                                {source.enabled ? 'Enabled' : 'Disabled'}
                                            </span>
                                            {source.consecutive_failures > 0 && (
                                                <span className="rounded-full bg-amber-100 px-2.5 py-0.5 text-xs text-amber-700 dark:bg-amber-900/40 dark:text-amber-200">
                                                    <span className="font-mono tabular-nums">{source.consecutive_failures}</span> consecutive failures
                                                </span>
                                            )}
                                        </div>
                                        <p className="text-sm text-muted-foreground">
                                            Cadence: every <span className="font-mono tabular-nums">{Math.round(source.cadence_minutes / 60)}</span>h ·
                                            Last run:{' '}
                                            {source.last_run_at ? (
                                                <RelativeDate iso={source.last_run_at} className="font-mono tabular-nums" />
                                            ) : (
                                                <span className="font-mono tabular-nums">never</span>
                                            )}{' '}
                                            ·
                                            Next:{' '}
                                            {source.next_run_at ? (
                                                <RelativeDate iso={source.next_run_at} className="font-mono tabular-nums" />
                                            ) : (
                                                <span className="font-mono tabular-nums">—</span>
                                            )}
                                        </p>
                                        {source.last_error && (
                                            <p className="text-sm text-amber-700 dark:text-amber-300">{source.last_error}</p>
                                        )}
                                    </div>
                                    <div className="flex shrink-0 gap-2">
                                        <Button size="sm" variant="outline" onClick={() => toggle(source.id)}>
                                            {source.enabled ? 'Disable' : 'Enable'}
                                        </Button>
                                        <Button size="sm" variant="default" onClick={() => runNow(source.id)}>
                                            Run now
                                        </Button>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </CardContent>
                </Card>

                <Card className="rounded-2xl border border-border bg-card shadow-sm">
                    <CardHeader>
                        <CardTitle>Auto-dismiss rules</CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        {activeRules.length === 0 ? (
                            <p className="px-6 py-4 text-sm text-muted-foreground">
                                No employers are auto-dismissed right now. Rules arm after 3 manual dismissals of the same employer.
                            </p>
                        ) : (
                            <ul className="divide-y divide-border">
                                {activeRules.map((rule) => (
                                    <li key={rule.id} className="flex items-center justify-between px-6 py-3 text-sm">
                                        <div>
                                            <span className="font-medium text-foreground">{rule.display_name}</span>
                                            <span className="ml-2 text-sm text-muted-foreground">
                                                <span className="font-mono tabular-nums">{rule.dismiss_count}</span> dismissals
                                                {rule.last_dismissed_at && (
                                                    <>
                                                        {' · last '}
                                                        <RelativeDate iso={rule.last_dismissed_at} className="font-mono tabular-nums" />
                                                    </>
                                                )}
                                            </span>
                                        </div>
                                        <Button size="sm" variant="ghost" onClick={() => revoke(rule.id)}>
                                            Revoke
                                        </Button>
                                    </li>
                                ))}
                            </ul>
                        )}
                        {inactiveRules.length > 0 && (
                            <p className="border-t border-border px-6 py-3 text-sm text-muted-foreground">
                                <span className="font-mono tabular-nums">{inactiveRules.length}</span> {inactiveRules.length === 1 ? 'employer has' : 'employers have'} dismissals on file but
                                no active rule yet.
                            </p>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
