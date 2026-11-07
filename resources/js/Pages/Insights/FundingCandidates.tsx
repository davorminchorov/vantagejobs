import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/components/PageHeader';
import { CompanyFundingGroup } from '@/components/funding/CompanyFundingGroup';
import { ConfirmFundingDialog, type ConfirmTarget } from '@/components/funding/ConfirmFundingDialog';
import type { FundingCompanyGroup, FundingSummary } from '@/components/funding/types';
import InsightsNav from '@/components/insights/InsightsNav';
import { EmptyState } from '@/components/ui/empty-state';

interface Props {
    groups: FundingCompanyGroup[];
    summary: FundingSummary;
}

export default function FundingCandidatesPage({ groups, summary }: Props) {
    const [confirmTarget, setConfirmTarget] = useState<ConfirmTarget | null>(null);

    const dismiss = (candidateId: number) => {
        router.post(`/insights/funding-candidates/${candidateId}/dismiss`, {}, { preserveScroll: true });
    };
    const dismissAll = (companyId: number) => {
        router.post(`/insights/funding-candidates/companies/${companyId}/dismiss`, {}, { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout>
            <Head title="Funding candidates" />
            <div className="flex flex-col gap-6">
                <PageHeader
                    title="Funding candidates"
                    subtitle={
                        summary.headlines > 0 ? (
                            <>
                                <span className="tabular-nums">
                                    {summary.headlines} {summary.headlines === 1 ? 'headline' : 'headlines'} across {summary.companies}{' '}
                                    {summary.companies === 1 ? 'company' : 'companies'}
                                </span>{' '}
                                from the last {summary.max_age_months} months. Confirm records the round on the company; dismiss drops the
                                headline.
                            </>
                        ) : (
                            'Funding news about tracked companies, waiting for review.'
                        )
                    }
                />
                <InsightsNav />

                {groups.length === 0 ? (
                    <EmptyState>
                        Nothing to review. Every Monday the news scan looks for funding headlines from the last {summary.max_age_months}{' '}
                        months that name a tracked company.
                    </EmptyState>
                ) : (
                    <div className="flex flex-col gap-4">
                        {groups.map((group) => (
                            <CompanyFundingGroup
                                key={group.company.id}
                                group={group}
                                onConfirm={(candidate) => setConfirmTarget({ candidate, company: group.company })}
                                onDismiss={(candidate) => dismiss(candidate.id)}
                                onDismissAll={() => dismissAll(group.company.id)}
                            />
                        ))}
                    </div>
                )}
            </div>

            <ConfirmFundingDialog target={confirmTarget} onClose={() => setConfirmTarget(null)} />
        </AuthenticatedLayout>
    );
}
