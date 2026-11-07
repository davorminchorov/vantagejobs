export type WindowFilter = 90 | 180 | 0;

export interface SalaryFilters {
    /** `tech`, `all`, or one discipline key. */
    discipline: string;
    /** `any` or one level key. */
    level: string;
    /** `any` or one location bucket. */
    region: string;
    window: WindowFilter;
}

export interface FilterOption {
    value: string;
    label: string;
}

export interface SalaryOptions {
    disciplines: FilterOption[];
    levels: FilterOption[];
    regions: FilterOption[];
}

export interface SalarySummary {
    samples: number;
    p25: number | null;
    median: number | null;
    p75: number | null;
    hourly_excluded: number;
    out_of_range_excluded: number;
}

export interface SalaryBucket {
    key: string;
    label: string;
    samples: number;
    p25: number | null;
    median: number | null;
    p75: number | null;
}

export interface SalarySample {
    id: number;
    url: string | null;
    title: string;
    company_name: string;
    /** Set when the listing is matched to a company on the watch list. */
    company_id: number | null;
    min_usd: number;
    max_usd: number;
    /** Currency the listing was posted in; amounts are converted to USD. */
    currency: string | null;
    region: string | null;
    discipline: string | null;
    level: string | null;
    seen_at: string;
}

export interface SalaryBounds {
    min_annual_usd: number;
    max_annual_usd: number;
}
