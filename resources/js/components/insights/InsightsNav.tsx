import { Link, usePage } from '@inertiajs/react';

import { cn } from '@/lib/utils';

interface InsightsTab {
    readonly name: string;
    readonly href: string;
}

const INSIGHTS_TABS: ReadonlyArray<InsightsTab> = [
    { name: 'Hiring trends', href: '/insights/hiring' },
    { name: 'Compensation', href: '/insights/comp' },
    { name: 'Funding candidates', href: '/insights/funding-candidates' },
];

/**
 * Tab row linking the three Insights pages, with the current page highlighted.
 */
export default function InsightsNav() {
    const { url } = usePage();
    const currentUrl = url.split('?')[0] ?? '/';

    return (
        <div
            role="tablist"
            aria-label="Insights"
            className="inline-flex items-center gap-1 rounded-md bg-muted p-1 text-muted-foreground"
        >
            {INSIGHTS_TABS.map((tab) => {
                const active = currentUrl === tab.href;
                return (
                    <Link
                        key={tab.href}
                        href={tab.href}
                        role="tab"
                        aria-selected={active}
                        aria-current={active ? 'page' : undefined}
                        className={cn(
                            'inline-flex h-9 items-center justify-center whitespace-nowrap rounded-sm px-3 text-sm font-medium transition-colors',
                            active ? 'bg-background text-foreground shadow-sm' : 'hover:text-foreground',
                        )}
                    >
                        {tab.name}
                    </Link>
                );
            })}
        </div>
    );
}
