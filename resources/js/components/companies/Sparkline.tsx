import { cn } from '@/lib/utils';

interface SparklineProps {
    /** Oldest first. Null marks days before the first snapshot. */
    values: (number | null)[];
    /** Noun phrase used in the accessible label, e.g. "Open roles over 90 days". */
    label: string;
    className?: string;
}

const WIDTH = 96;
const HEIGHT = 24;
const PAD = 2;

/** Tiny inline SVG line chart. Flat series draw a baseline instead of dividing by zero. */
const MIN_POINTS = 7;

/**
 * Tiny inline SVG line chart. Plots only from the first non-null point; with
 * fewer than 7 points there is no meaningful trend, so a muted label shows.
 */
export default function Sparkline({ values, label, className }: SparklineProps) {
    const firstIndex = values.findIndex((v) => v !== null);
    const series = firstIndex === -1 ? [] : (values.slice(firstIndex) as number[]);

    if (series.length < MIN_POINTS) {
        return (
            <span className="text-sm text-muted-foreground sm:text-xs" aria-label={`${label}: not enough history yet`}>
                Collecting
            </span>
        );
    }

    const min = Math.min(...series);
    const max = Math.max(...series);
    const range = max - min;
    const step = (WIDTH - PAD * 2) / (series.length - 1);

    const points = series.map((value, index) => {
        const x = PAD + index * step;
        const y = range === 0 ? HEIGHT - PAD : HEIGHT - PAD - ((value - min) / range) * (HEIGHT - PAD * 2);
        return `${x.toFixed(1)},${y.toFixed(1)}`;
    });

    const first = series[0];
    const last = series[series.length - 1];
    const description = `${label}: ${first} to ${last} over ${series.length} days`;

    return (
        <svg
            role="img"
            aria-label={description}
            viewBox={`0 0 ${WIDTH} ${HEIGHT}`}
            className={cn('h-6 w-24 text-primary', className)}
            preserveAspectRatio="none"
        >
            <title>{description}</title>
            <polyline
                points={points.join(' ')}
                fill="none"
                stroke="currentColor"
                strokeWidth="1.5"
                strokeLinejoin="round"
                strokeLinecap="round"
                vectorEffect="non-scaling-stroke"
            />
        </svg>
    );
}
