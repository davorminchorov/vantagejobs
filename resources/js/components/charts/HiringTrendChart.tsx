import { CartesianGrid, Legend, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

import { formatDate } from '@/lib/dates';

interface TrendPoint {
    date: string;
    open_role_count: number;
    listings_count?: number;
}

interface Props {
    data: TrendPoint[];
    showListings?: boolean;
    /** A single line is named by the section title, so the legend can be hidden. */
    showLegend?: boolean;
    /**
     * `step` draws counts as flat until the next snapshot changes them, which
     * is how daily counts behave; `smooth` interpolates between points.
     */
    curve?: 'smooth' | 'step';
    height?: number;
    /** Render the empty state as a single slim line instead of a full-height box. */
    compact?: boolean;
}

const AXIS_TICK = { fill: 'var(--muted-foreground)', fontSize: 12 };

const COMPACT_NUMBER = new Intl.NumberFormat(undefined, { notation: 'compact', maximumFractionDigits: 1 });

/** "Aug 12" for a YYYY-MM-DD string, read as a calendar date. */
function shortDate(date: string): string {
    const parsed = new Date(`${date}T00:00:00`);
    return Number.isNaN(parsed.getTime()) ? date : parsed.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
}

export default function HiringTrendChart({
    data,
    showListings = true,
    showLegend = true,
    curve = 'smooth',
    height = 240,
    compact = false,
}: Props) {
    if (data.length === 0) {
        if (compact) {
            return (
                <p className="text-base text-pretty text-muted-foreground sm:text-sm">
                    No snapshots yet. Charts populate after the first daily run.
                </p>
            );
        }
        return (
            <div
                className="flex items-center justify-center rounded-xl border border-dashed border-border text-base text-muted-foreground sm:text-sm"
                style={{ height }}
            >
                No snapshots yet. Charts populate after the first daily run.
            </div>
        );
    }

    return (
        <ResponsiveContainer width="100%" height={height}>
            <LineChart data={data} margin={{ top: 8, right: 12, left: 0, bottom: 4 }}>
                <CartesianGrid vertical={false} stroke="var(--border)" />
                <XAxis
                    dataKey="date"
                    tickFormatter={shortDate}
                    minTickGap={32}
                    tick={AXIS_TICK}
                    tickLine={false}
                    axisLine={{ stroke: 'var(--border)' }}
                />
                <YAxis
                    allowDecimals={false}
                    tickFormatter={(value: number) => COMPACT_NUMBER.format(value)}
                    tick={AXIS_TICK}
                    tickLine={false}
                    axisLine={false}
                    width={40}
                />
                <Tooltip
                    contentStyle={{
                        fontSize: 12,
                        fontVariantNumeric: 'tabular-nums',
                        background: 'var(--card)',
                        color: 'var(--card-foreground)',
                        border: '1px solid var(--border)',
                        borderRadius: 8,
                        boxShadow: 'none',
                    }}
                    cursor={{ stroke: 'var(--border)' }}
                    labelFormatter={(label) => formatDate(String(label))}
                />
                {showLegend ? <Legend wrapperStyle={{ fontSize: 12, color: 'var(--muted-foreground)' }} /> : null}
                <Line
                    type={curve === 'step' ? 'stepAfter' : 'monotone'}
                    dataKey="open_role_count"
                    name="Open roles"
                    stroke="var(--primary)"
                    strokeWidth={2}
                    dot={false}
                />
                {showListings ? (
                    <Line
                        type="monotone"
                        dataKey="listings_count"
                        name="Aggregator listings"
                        stroke="var(--muted-foreground)"
                        strokeWidth={1.5}
                        strokeDasharray="4 4"
                        dot={false}
                    />
                ) : null}
            </LineChart>
        </ResponsiveContainer>
    );
}
