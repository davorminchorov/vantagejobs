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

/**
 * Shared axis for a set of range rows: P25 of the lowest row to P75 of the
 * highest, widened to whole $25k steps so the tick labels read cleanly.
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

    const step = 25_000;
    const min = Math.floor(Math.min(...lows) / step) * step;
    let max = Math.ceil(Math.max(...highs) / step) * step;
    if (max === min) {
        max = min + step;
    }

    const span = max - min;
    const tickStep = span > 300_000 ? 100_000 : span > 150_000 ? 50_000 : step;
    const ticks: number[] = [];
    for (let tick = Math.ceil(min / tickStep) * tickStep; tick <= max; tick += tickStep) {
        ticks.push(tick);
    }

    return { min, max, ticks };
}

/** Position of a value on the scale, as a percentage. */
export function percentOf(value: number, scale: { min: number; max: number }): number {
    return Math.min(100, Math.max(0, ((value - scale.min) / (scale.max - scale.min)) * 100));
}
