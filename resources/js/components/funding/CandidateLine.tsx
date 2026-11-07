import { X } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatDate } from '@/lib/dates';
import { cn } from '@/lib/utils';

import { safeArticleUrl } from './helpers';
import type { FundingCandidateItem } from './types';

export interface CandidateLineProps {
    candidate: FundingCandidateItem;
    isLast: boolean;
    onConfirm: () => void;
    onDismiss: () => void;
}

/** One headline on a company's timeline: date, headline, publisher, parsed round and amount, actions. */
export function CandidateLine({ candidate, isLast, onConfirm, onDismiss }: CandidateLineProps) {
    const url = safeArticleUrl(candidate.url);

    return (
        <li className="relative flex gap-3 py-3 pr-3 pl-8 sm:gap-4 sm:pr-4 sm:pl-9">
            <span aria-hidden="true" className={cn('absolute top-0 left-4 w-px bg-border sm:left-[1.125rem]', isLast ? 'h-5' : 'h-full')} />
            <span
                aria-hidden="true"
                className="absolute top-[1.0625rem] left-[0.8125rem] size-[0.4375rem] rounded-full bg-muted-foreground/60 ring-4 ring-background sm:left-[0.9375rem]"
            />

            <div className="flex min-w-0 flex-1 flex-col gap-1 sm:flex-row sm:items-baseline sm:gap-4">
                <span className="shrink-0 text-sm text-muted-foreground tabular-nums sm:w-24 sm:text-xs">
                    {formatDate(candidate.published_at)}
                </span>
                <div className="flex min-w-0 flex-col gap-1.5">
                    {url ? (
                        <a
                            href={url}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="text-base text-pretty text-foreground hover:underline sm:text-sm"
                        >
                            {candidate.headline}
                        </a>
                    ) : (
                        <span className="text-base text-pretty sm:text-sm">{candidate.headline}</span>
                    )}
                    <div className="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-muted-foreground sm:text-xs">
                        {candidate.publisher ? <span>{candidate.publisher}</span> : null}
                        {candidate.stage ? <Badge variant="secondary">{candidate.stage}</Badge> : null}
                        {candidate.amount_label ? (
                            <Badge variant="secondary" className="tabular-nums">
                                {candidate.amount_label}
                            </Badge>
                        ) : null}
                    </div>
                </div>
            </div>

            <div className="flex shrink-0 items-start gap-1">
                <Button type="button" size="sm" variant="outline" onClick={onConfirm}>
                    Confirm
                </Button>
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    onClick={onDismiss}
                    aria-label={`Dismiss: ${candidate.headline}`}
                    title="Dismiss"
                    className="text-muted-foreground hover:text-foreground"
                >
                    <X aria-hidden="true" />
                </Button>
            </div>
        </li>
    );
}
