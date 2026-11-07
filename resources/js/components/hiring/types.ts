export type TierFilter = 'all' | 'A' | 'B' | 'C';

export interface HiringSummary {
    open_now: number;
    /** Null until there are snapshots from that far back. */
    change_7d: number | null;
    change_30d: number | null;
    new_7d: number;
    closed_7d: number;
    companies_hiring: number;
    companies_total: number;
}

export interface HiringPoint {
    date: string;
    open_role_count: number;
}

export interface CompanyMomentum {
    id: number;
    name: string;
    tier: string;
    open_now: number;
    /** 90 daily open-role counts, oldest first; null before the first snapshot. */
    trend: (number | null)[];
    change_7d: number | null;
    change_30d: number | null;
    new_7d: number;
    closed_7d: number;
}

export type SortKey = 'name' | 'open_now' | 'change_7d' | 'change_30d' | 'new_7d' | 'closed_7d';

export interface SortState {
    key: SortKey;
    direction: 'asc' | 'desc';
}
