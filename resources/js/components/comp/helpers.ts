/** Fewer samples than this and a percentile says little; such rows are shown muted. */
export const MIN_RELIABLE_SAMPLES = 5;

/** "$148k" for 148_000, "$1.2M" for 1_200_000, "n/a" when unknown. */
export function formatUsd(value: number | null): string {
    if (value === null || Number.isNaN(value)) {
        return 'n/a';
    }
    if (value >= 1_000_000) {
        return `$${(value / 1_000_000).toFixed(1).replace(/\.0$/, '')}M`;
    }
    return `$${Math.round(value / 1000)}k`;
}

/** "$120k to $175k", or a single amount when both ends match. */
export function formatRange(min: number | null, max: number | null): string {
    if (min === null || max === null) {
        return 'n/a';
    }
    return Math.round(min / 1000) === Math.round(max / 1000) ? formatUsd(min) : `${formatUsd(min)} to ${formatUsd(max)}`;
}

const NICE_TICK_STEPS = [10_000, 20_000, 25_000, 50_000, 100_000, 200_000, 250_000, 500_000];
const MAX_TICKS = 5;

/**
 * Shared axis for a set of range rows: P25 of the lowest row to P75 of the
 * highest, widened to a "nice" tick step chosen so at most MAX_TICKS labels
 * render (they collide in a narrow track otherwise).
 */
export function rangeScale(rows: ReadonlyArray<{ p25: number | null; p75: number | null }>): {
    min: number;
    max: number;
    ticks: number[];
} {
    const lows = rows.map((row) => row.p25).filter((value): value is number => value !== null);
    const highs = rows.map((row) => row.p75).filter((value): value is number => value !== null);
    if (lows.length === 0 || highs.length === 0) {
        return { min: 0, max: 1, ticks: [] };
    }

    const rawMin = Math.min(...lows);
    const rawMax = Math.max(...highs);
    let step = 500_000;
    for (const candidate of NICE_TICK_STEPS) {
        const span = Math.max(Math.ceil(rawMax / candidate) * candidate - Math.floor(rawMin / candidate) * candidate, candidate);
        if (span / candidate + 1 <= MAX_TICKS) {
            step = candidate;
            break;
        }
    }

    const min = Math.floor(rawMin / step) * step;
    let max = Math.ceil(rawMax / step) * step;
    if (max === min) {
        max = min + step;
    }

    const ticks: number[] = [];
    for (let tick = min; tick <= max; tick += step) {
        ticks.push(tick);
    }

    return { min, max, ticks };
}

/** Position of a value on the scale, as a percentage. */
export function percentOf(value: number, scale: { min: number; max: number }): number {
    return Math.min(100, Math.max(0, ((value - scale.min) / (scale.max - scale.min)) * 100));
}
