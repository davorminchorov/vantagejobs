import { Link } from '@inertiajs/react';

import { RelativeDate } from '@/lib/dates';

import { formatRange } from './helpers';
import type { SalarySample } from './types';

function safeUrl(url: string | null): string | null {
    if (url === null) {
        return null;
    }
    try {
        const parsed = new URL(url);
        return parsed.protocol === 'http:' || parsed.protocol === 'https:' ? parsed.toString() : null;
    } catch {
        return null;
    }
}

/** The newest listings behind the numbers, so a median can be checked against real postings. */
export function RecentSalaries({ samples }: { samples: SalarySample[] }) {
    return (
        <ul role="list" className="divide-y divide-border border-y border-border">
            {samples.map((sample) => {
                const url = safeUrl(sample.url);
                const tags = [sample.level, sample.discipline, sample.region].filter(Boolean).join(' · ');

                return (
                    <li key={sample.id} className="flex flex-col gap-1 py-3 sm:flex-row sm:items-baseline sm:gap-6">
                        <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                            {url ? (
                                <a
                                    href={url}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="truncate text-base font-medium text-foreground hover:underline sm:text-sm"
                                >
                                    {sample.title}
                                </a>
                            ) : (
                                <span className="truncate text-base font-medium sm:text-sm">{sample.title}</span>
                            )}
                            <span className="text-sm text-muted-foreground">
                                {sample.company_id !== null ? (
                                    <Link href={`/companies/${sample.company_id}`} className="text-primary hover:underline">
                                        {sample.company_name}
                                    </Link>
                                ) : (
                                    sample.company_name
                                )}
                                {tags !== '' ? <span> · {tags}</span> : null}
                            </span>
                        </div>
                        <div className="flex items-baseline gap-3 text-sm sm:shrink-0">
                            <span className="font-medium text-foreground tabular-nums">
                                {formatRange(sample.min_usd, sample.max_usd)}
                            </span>
                            {sample.currency !== null && sample.currency !== 'USD' ? (
                                <span className="text-muted-foreground" title={`Posted in ${sample.currency}, converted to USD`}>
                                    from {sample.currency}
                                </span>
                            ) : null}
                            <RelativeDate iso={sample.seen_at} className="text-muted-foreground sm:w-20 sm:text-right" />
                        </div>
                    </li>
                );
            })}
        </ul>
    );
}
