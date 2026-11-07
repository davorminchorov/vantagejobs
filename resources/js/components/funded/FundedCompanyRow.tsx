import { Link } from '@inertiajs/react';
import { X } from 'lucide-react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatDate } from '@/lib/dates';

import type { FundedCompanyGroup } from './types';

const REGION_LABELS: Record<FundedCompanyGroup['regions'][number], string> = {
    us: 'US',
    uk: 'UK',
    europe: 'Europe',
    global: 'Global',
};

function safeUrl(url: string): string | null {
    try {
        const parsed = new URL(url);
        return parsed.protocol === 'http:' || parsed.protocol === 'https:' ? parsed.toString() : null;
    } catch {
        return null;
    }
}

export interface FundedCompanyRowProps {
    group: FundedCompanyGroup;
    onDismiss: () => void;
}

/** One company that announced a round: name, round, amount, the newest headline, and Track / Dismiss. */
export function FundedCompanyRow({ group, onDismiss }: FundedCompanyRowProps) {
    const [showAll, setShowAll] = useState(false);
    const older = group.headlines.slice(1);
    const visible = showAll ? group.headlines : group.headlines.slice(0, 1);
    const name = group.company_name;

    return (
        <li className="flex flex-col gap-3 py-4 sm:flex-row sm:items-start sm:gap-6">
            <div className="flex min-w-0 flex-1 flex-col gap-2">
                <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                    <h2 className="text-base font-semibold">{name ?? 'Company not named'}</h2>
                    {group.stage ? <Badge variant="secondary">{group.stage}</Badge> : null}
                    {group.amount_label ? (
                        <Badge variant="secondary" className="tabular-nums">
                            {group.amount_label}
                        </Badge>
                    ) : null}
                    {group.regions.map((region) => (
                        <Badge key={region} variant="outline">
                            {REGION_LABELS[region]}
                        </Badge>
                    ))}
                </div>

                {group.industries.length > 0 || group.is_software !== null ? (
                    <p className="flex flex-wrap items-center gap-x-1.5 gap-y-1 text-sm text-muted-foreground">
                        {group.is_software !== null ? (
                            <Badge variant={group.is_software ? 'default' : 'secondary'}>
                                {group.is_software ? 'Software' : 'Hardware / deep tech'}
                            </Badge>
                        ) : null}
                        {group.industries.length > 0 ? <span>{group.industries.join(' · ')}</span> : null}
                    </p>
                ) : null}

                <ul role="list" className="flex flex-col gap-2">
                    {visible.map((headline) => {
                        const url = safeUrl(headline.url);
                        return (
                            <li key={headline.id} className="flex flex-col gap-0.5">
                                {url ? (
                                    <a
                                        href={url}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="text-base text-pretty text-foreground hover:underline sm:text-sm"
                                    >
                                        {headline.title}
                                    </a>
                                ) : (
                                    <span className="text-base text-pretty sm:text-sm">{headline.title}</span>
                                )}
                                {headline.summary ? (
                                    <p className="line-clamp-2 max-w-prose text-sm text-pretty text-muted-foreground">
                                        {headline.summary}
                                    </p>
                                ) : null}
                                <span className="text-sm text-muted-foreground sm:text-xs">
                                    {headline.source} · <span className="tabular-nums">{formatDate(headline.published_at)}</span>
                                </span>
                            </li>
                        );
                    })}
                </ul>

                {older.length > 0 ? (
                    <button
                        type="button"
                        onClick={() => setShowAll((value) => !value)}
                        className="self-start text-sm font-medium text-muted-foreground hover:text-foreground"
                    >
                        {showAll ? 'Show fewer' : `Also covered in ${older.length} more ${older.length === 1 ? 'article' : 'articles'}`}
                    </button>
                ) : null}
            </div>

            <div className="flex shrink-0 items-center gap-1">
                {group.tracked_company ? (
                    <Button asChild size="sm" variant="outline">
                        <Link href={`/companies/${group.tracked_company.id}`}>Tracked: view</Link>
                    </Button>
                ) : (
                    <>
                        <Button asChild size="sm" variant="outline">
                            <Link href={`/companies/create?funding_announcement=${group.announcement_id}`}>Track</Link>
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            onClick={onDismiss}
                            aria-label={`Dismiss ${name ?? group.headlines[0]?.title ?? 'headline'}`}
                            title="Dismiss"
                            className="text-muted-foreground hover:text-foreground"
                        >
                            <X aria-hidden="true" />
                        </Button>
                    </>
                )}
            </div>
        </li>
    );
}
