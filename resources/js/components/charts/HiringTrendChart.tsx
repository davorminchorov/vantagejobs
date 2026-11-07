import {
    CartesianGrid,
    Legend,
    Line,
    LineChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';

import { formatDate } from '@/lib/dates';

interface TrendPoint {
    date: string;
    open_role_count: number;
    listings_count: number;
}

interface Props {
    data: TrendPoint[];
    showListings?: boolean;
    height?: number;
    /** Render the empty state as a single slim line instead of a full-height box. */
    compact?: boolean;
}

const AXIS_TICK = { fill: 'var(--muted-foreground)', fontSize: 12 };

export default function HiringTrendChart({ data, showListings = true, height = 240, compact = false }: Props) {
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
                    tickFormatter={(d: string) => d.slice(5)}
                    tick={AXIS_TICK}
                    tickLine={false}
                    axisLine={{ stroke: 'var(--border)' }}
                />
                <YAxis
                    allowDecimals={false}
                    tick={AXIS_TICK}
                    tickLine={false}
                    axisLine={false}
                    width={36}
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
                <Legend wrapperStyle={{ fontSize: 12, color: 'var(--muted-foreground)' }} />
                <Line
                    type="monotone"
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
