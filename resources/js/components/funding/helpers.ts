import type { FundingCandidateItem, FundingOnFile } from './types';

/** "$150M", "$1.3B", "$500k". */
export function formatUsd(amount: number): string {
    if (amount >= 1_000_000_000) {
        return `$${trim(amount / 1_000_000_000)}B`;
    }
    if (amount >= 1_000_000) {
        return `$${trim(amount / 1_000_000)}M`;
    }
    if (amount >= 1_000) {
        return `$${trim(amount / 1_000)}k`;
    }
    return `$${amount.toLocaleString()}`;
}

function trim(value: number): string {
    return value.toLocaleString(undefined, { maximumFractionDigits: 2 });
}

/** "Mar 2026" from a YYYY-MM-DD or ISO string. */
export function monthYear(iso: string | null): string | null {
    if (!iso) {
        return null;
    }
    const date = new Date(iso.length === 10 ? `${iso}T00:00:00` : iso);
    if (Number.isNaN(date.getTime())) {
        return null;
    }
    return date.toLocaleDateString(undefined, { month: 'short', year: 'numeric' });
}

/** "Series C · $50M · Mar 2026", or null when nothing is on file. */
export function onFileLabel(funding: FundingOnFile): string | null {
    const parts = [
        funding.stage,
        funding.amount_usd ? formatUsd(funding.amount_usd) : null,
        monthYear(funding.raised_at),
    ].filter((part): part is string => Boolean(part));

    return parts.length > 0 ? parts.join(' · ') : null;
}

/** The newest headline that names a round or an amount, used as the group's summary. */
export function strongestSignal(candidates: FundingCandidateItem[]): FundingCandidateItem | null {
    return candidates.find((candidate) => candidate.stage !== null || candidate.amount_label !== null) ?? null;
}

/** "Series D · $150M" for a candidate, or null. */
export function signalLabel(candidate: FundingCandidateItem): string | null {
    const parts = [candidate.stage, candidate.amount_label].filter((part): part is string => Boolean(part));
    return parts.length > 0 ? parts.join(' · ') : null;
}

/** Only http(s) links are rendered as anchors. */
export function safeArticleUrl(url: string): string | null {
    try {
        const parsed = new URL(url);
        return parsed.protocol === 'http:' || parsed.protocol === 'https:' ? parsed.toString() : null;
    } catch {
        return null;
    }
}

/** YYYY-MM-DD for a date input. */
export function dateInputValue(iso: string | null): string {
    return iso ? iso.slice(0, 10) : '';
}
