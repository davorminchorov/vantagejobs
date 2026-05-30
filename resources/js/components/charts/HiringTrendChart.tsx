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

interface TrendPoint {
    date: string;
    open_role_count: number;
    listings_count: number;
}

interface Props {
    data: TrendPoint[];
    showListings?: boolean;
    height?: number;
}

export default function HiringTrendChart({ data, showListings = true, height = 240 }: Props) {
    if (data.length === 0) {
        return (
            <div
                className="flex items-center justify-center rounded-2xl border border-dashed border-border bg-card text-sm text-muted-foreground"
                style={{ height }}
            >
                No snapshots yet. Charts populate after the first daily run.
            </div>
        );
    }

    return (
        <ResponsiveContainer width="100%" height={height}>
            <LineChart data={data} margin={{ top: 8, right: 12, left: 0, bottom: 4 }}>
                <CartesianGrid strokeDasharray="3 3" className="stroke-muted" />
                <XAxis
                    dataKey="date"
                    tickFormatter={(d: string) => d.slice(5)}
                    fontSize={11}
                    stroke="currentColor"
                    className="font-mono tabular-nums text-muted-foreground"
                />
                <YAxis
                    fontSize={11}
                    allowDecimals={false}
                    stroke="currentColor"
                    className="font-mono tabular-nums text-muted-foreground"
                />
                <Tooltip
                    contentStyle={{ fontSize: 12 }}
                    wrapperClassName="rounded-md border border-border bg-card text-card-foreground tabular-nums shadow-sm"
                    labelFormatter={(d: string) => new Date(d).toLocaleDateString()}
                />
                <Legend wrapperStyle={{ fontSize: 12 }} />
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
