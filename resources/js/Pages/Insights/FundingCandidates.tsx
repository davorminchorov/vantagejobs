import { Head, Link, router } from '@inertiajs/react';

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/components/PageHeader';
import InsightsNav from '@/components/insights/InsightsNav';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';

interface Candidate {
    id: number;
    company: { id: number; name: string };
    title: string;
    url: string;
    matched_keywords: string[];
    published_at: string | null;
    fetched_at: string | null;
}

interface Props {
    candidates: Candidate[];
}

export default function FundingCandidatesPage({ candidates }: Props) {
    const confirm = (id: number) => {
        router.post(`/insights/funding-candidates/${id}/confirm`, {}, { preserveScroll: true });
    };
    const dismiss = (id: number) => {
        router.post(`/insights/funding-candidates/${id}/dismiss`, {}, { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout>
            <Head title="Funding candidates" />
            <div className="flex flex-col gap-6">
                <PageHeader
                    title="Funding candidates"
                    subtitle="Google News hits awaiting review. Confirm to record a funding event on the company, or dismiss to drop the row from this queue."
                />

                <InsightsNav />

                {candidates.length === 0 ? (
                    <EmptyState>Nothing to review.</EmptyState>
                ) : (
                    <ul role="list" className="divide-y divide-border border-y border-border">
                        {candidates.map((c) => (
                            <li
                                key={c.id}
                                className="flex flex-col gap-3 py-4 sm:flex-row sm:items-start sm:justify-between sm:gap-4"
                            >
                                <div className="flex min-w-0 flex-col gap-2">
                                    <a
                                        href={c.url}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="text-base font-medium text-pretty text-foreground hover:underline"
                                    >
                                        {c.title}
                                    </a>
                                    <div className="text-base text-muted-foreground sm:text-sm">
                                        <Link href={`/companies/${c.company.id}`} className="hover:underline">
                                            {c.company.name}
                                        </Link>
                                        {c.published_at ? (
                                            <span>
                                                {' · '}
                                                <span className="tabular-nums">
                                                    {new Date(c.published_at).toLocaleDateString()}
                                                </span>
                                            </span>
                                        ) : null}
                                    </div>
                                    {c.matched_keywords.length > 0 ? (
                                        <div className="flex flex-wrap gap-1.5">
                                            {c.matched_keywords.map((k) => (
                                                <Badge key={k} variant="secondary">
                                                    {k}
                                                </Badge>
                                            ))}
                                        </div>
                                    ) : null}
                                </div>
                                <div className="flex shrink-0 gap-2">
                                    <Button type="button" size="sm" variant="outline" onClick={() => confirm(c.id)}>
                                        Confirm
                                    </Button>
                                    <Button type="button" size="sm" variant="ghost" onClick={() => dismiss(c.id)}>
                                        Dismiss
                                    </Button>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
