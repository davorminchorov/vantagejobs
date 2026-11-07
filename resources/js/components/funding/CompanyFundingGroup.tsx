import { Link } from '@inertiajs/react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

import { CandidateLine } from './CandidateLine';
import { onFileLabel, signalLabel, strongestSignal } from './helpers';
import type { FundingCandidateItem, FundingCompanyGroup as Group } from './types';

const VISIBLE_HEADLINES = 3;

export interface CompanyFundingGroupProps {
    group: Group;
    onConfirm: (candidate: FundingCandidateItem) => void;
    onDismiss: (candidate: FundingCandidateItem) => void;
    onDismissAll: () => void;
}

/** A company's funding headlines as a short timeline, newest first, next to the funding already on file. */
export function CompanyFundingGroup({ group, onConfirm, onDismiss, onDismissAll }: CompanyFundingGroupProps) {
    const [expanded, setExpanded] = useState(false);
    const { company, candidates } = group;
    const onFile = onFileLabel(company.funding);
    const signal = strongestSignal(candidates);
    const signalText = signal ? signalLabel(signal) : null;
    const visible = expanded ? candidates : candidates.slice(0, VISIBLE_HEADLINES);
    const hidden = candidates.length - visible.length;

    return (
        <section aria-labelledby={`funding-company-${company.id}`} className="rounded-xl border border-border">
            <header className="flex flex-wrap items-start gap-x-4 gap-y-3 border-b border-border px-3 py-3 sm:px-4">
                <div className="flex min-w-0 flex-1 flex-col gap-1">
                    <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                        <h2 id={`funding-company-${company.id}`} className="text-base font-semibold">
                            <Link href={`/companies/${company.id}`} className="hover:underline">
                                {company.name}
                            </Link>
                        </h2>
                        {company.tier ? (
                            <Badge variant={company.tier === 'A' ? 'default' : company.tier === 'B' ? 'secondary' : 'outline'}>
                                Tier {company.tier}
                            </Badge>
                        ) : null}
                        {signalText ? (
                            <span className="text-sm font-medium text-foreground tabular-nums">
                                Headlines say {signalText}
                            </span>
                        ) : null}
                    </div>
                    <p className="text-sm text-muted-foreground">
                        {onFile ? (
                            <>
                                On file: <span className="text-foreground tabular-nums">{onFile}</span>
                            </>
                        ) : (
                            'No funding on file'
                        )}
                    </p>
                </div>
                <Button type="button" size="sm" variant="outline" onClick={onDismissAll}>
                    {candidates.length > 1 ? `Dismiss all ${candidates.length}` : 'Dismiss'}
                </Button>
            </header>

            <ul role="list" className="py-1">
                {visible.map((candidate, index) => (
                    <CandidateLine
                        key={candidate.id}
                        candidate={candidate}
                        isLast={index === visible.length - 1 && hidden === 0}
                        onConfirm={() => onConfirm(candidate)}
                        onDismiss={() => onDismiss(candidate)}
                    />
                ))}
            </ul>

            {candidates.length > VISIBLE_HEADLINES ? (
                <div className="border-t border-border px-3 py-2 sm:px-4">
                    <Button type="button" size="sm" variant="ghost" onClick={() => setExpanded((value) => !value)}>
                        {expanded ? 'Show fewer' : `Show ${hidden} more ${hidden === 1 ? 'headline' : 'headlines'}`}
                    </Button>
                </div>
            ) : null}
        </section>
    );
}
