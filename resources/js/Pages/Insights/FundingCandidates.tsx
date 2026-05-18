import { Head, Link, router } from '@inertiajs/react';

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

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
            <div className="space-y-6">
                <header>
                    <h1 className="text-2xl font-semibold tracking-tight">Funding candidates</h1>
                    <p className="text-sm text-muted-foreground">
                        Google News hits awaiting review. Confirm to record a funding event on the
                        company, or dismiss to drop the row from this queue.
                    </p>
                </header>

                {candidates.length === 0 ? (
                    <Card>
                        <CardContent className="py-10 text-center text-sm text-muted-foreground">
                            Nothing to review.
                        </CardContent>
                    </Card>
                ) : (
                    <div className="space-y-3">
                        {candidates.map((c) => (
                            <Card key={c.id}>
                                <CardHeader className="flex flex-row items-start justify-between space-y-0 gap-4">
                                    <div className="space-y-1">
                                        <CardTitle className="text-base font-semibold leading-snug">
                                            <a
                                                href={c.url}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className="hover:underline"
                                            >
                                                {c.title}
                                            </a>
                                        </CardTitle>
                                        <div className="text-sm text-muted-foreground">
                                            <Link
                                                href={`/companies/${c.company.id}`}
                                                className="hover:underline"
                                            >
                                                {c.company.name}
                                            </Link>
                                            {c.published_at ? (
                                                <span> · {new Date(c.published_at).toLocaleDateString()}</span>
                                            ) : null}
                                        </div>
                                    </div>
                                    <div className="flex flex-shrink-0 gap-2">
                                        <Button size="sm" onClick={() => confirm(c.id)}>
                                            Confirm
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() => dismiss(c.id)}
                                        >
                                            Dismiss
                                        </Button>
                                    </div>
                                </CardHeader>
                                <CardContent>
                                    <div className="flex flex-wrap gap-1.5">
                                        {c.matched_keywords.map((k) => (
                                            <Badge key={k} variant="secondary">
                                                {k}
                                            </Badge>
                                        ))}
                                    </div>
                                </CardContent>
                            </Card>
                        ))}
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
