export type RegionFilter = 'all' | 'us' | 'uk' | 'europe' | 'global';
export type RoundFilter = 'all' | 'seed' | 'early' | 'growth';
export type TrackedFilter = 'hide' | 'show';

export interface FundedHeadline {
    id: number;
    title: string;
    url: string;
    /** Feed name, e.g. "TechCrunch". */
    source: string;
    published_at: string | null;
}

export interface FundedCompanyGroup {
    key: string;
    /** Newest headline; used for Track and Dismiss. */
    announcement_id: number;
    /** Null when no headline names who raised. */
    company_name: string | null;
    stage: string | null;
    amount_label: string | null;
    regions: Array<'us' | 'uk' | 'europe' | 'global'>;
    latest_published_at: string | null;
    tracked_company: { id: number; name: string } | null;
    headlines: FundedHeadline[];
}

export interface RecentlyFundedFilters {
    region: RegionFilter;
    round: RoundFilter;
    tracked: TrackedFilter;
}
