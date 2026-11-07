import { ChangeValue } from './ChangeValue';
import type { HiringSummary } from './types';

function Stat({ label, children, detail }: { label: string; children: React.ReactNode; detail: React.ReactNode }) {
    return (
        <div className="flex flex-col gap-1 py-4 sm:px-5 sm:first:pl-0">
            <dt className="text-base text-muted-foreground sm:text-sm">{label}</dt>
            <dd className="text-2xl font-semibold tracking-tight tabular-nums">{children}</dd>
            <dd className="text-sm text-muted-foreground">{detail}</dd>
        </div>
    );
}

/** Headline numbers for the current tier filter. */
export function HiringStats({ summary }: { summary: HiringSummary }) {
    return (
        <dl className="grid grid-cols-2 gap-x-6 border-y border-border sm:grid-cols-4 sm:gap-x-0 sm:divide-x sm:divide-border">
            <Stat
                label="Open roles"
                detail={
                    <span className="tabular-nums">
                        at {summary.companies_hiring} of {summary.companies_total} companies
                    </span>
                }
            >
                {summary.open_now.toLocaleString()}
            </Stat>
            <Stat label="Change, 7 days" detail="vs a week ago">
                <ChangeValue value={summary.change_7d} period="7 days" />
            </Stat>
            <Stat label="Change, 30 days" detail="vs a month ago">
                <ChangeValue value={summary.change_30d} period="30 days" />
            </Stat>
            <Stat label="This week" detail="opened / closed">
                <span>
                    {summary.new_7d.toLocaleString()}
                    <span className="text-muted-foreground"> / </span>
                    {summary.closed_7d.toLocaleString()}
                </span>
            </Stat>
        </dl>
    );
}
