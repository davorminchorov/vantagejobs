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
 * Tab row linking the three Insights pages, with the current page underlined.
 * Scrolls horizontally on narrow screens instead of overflowing the page.
 */
export default function InsightsNav() {
    const { url } = usePage();
    const currentUrl = url.split('?')[0] ?? '/';

    return (
        <div className="-mx-4 overflow-x-auto px-4 shadow-[inset_0_-1px_0_var(--border)] sm:mx-0 sm:px-0">
            <nav role="tablist" aria-label="Insights" className="flex min-w-max items-center gap-6">
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
                                'inline-flex items-center whitespace-nowrap border-b-2 py-2.5 text-base font-medium sm:text-sm',
                                active
                                    ? 'border-primary text-foreground'
                                    : 'border-transparent text-muted-foreground hover:text-foreground',
                            )}
                        >
                            {tab.name}
                        </Link>
                    );
                })}
            </nav>
        </div>
    );
}
