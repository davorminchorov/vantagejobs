export interface FundingOnFile {
    stage: string | null;
    amount_usd: number | null;
    /** YYYY-MM-DD */
    raised_at: string | null;
}

export interface FundingCandidateItem {
    id: number;
    /** Headline with the " - Publisher" suffix removed. */
    headline: string;
    publisher: string | null;
    url: string;
    published_at: string | null;
    /** Round parsed from the headline, e.g. "Series D". */
    stage: string | null;
    /** Dollar amount parsed from the headline; null for other currencies. */
    amount_usd: number | null;
    /** Amount as written, e.g. "$150M" or "€60M". */
    amount_label: string | null;
}

export interface FundingCompanyGroup {
    company: {
        id: number;
        name: string;
        tier: string | null;
        funding: FundingOnFile;
    };
    latest_published_at: string | null;
    candidates: FundingCandidateItem[];
}

export interface FundingSummary {
    headlines: number;
    companies: number;
    max_age_months: number;
}
