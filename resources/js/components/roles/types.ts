export interface RoleItem {
    id: number;
    title: string;
    url: string | null;
    location: string | null;
    workplace_type: string | null;
    department: string | null;
    team: string | null;
    comp_min: number | null;
    comp_max: number | null;
    comp_currency: string | null;
    comp_interval: string | null;
    posted_at: string | null;
    created_at: string;
    source: string;
    pipeline_status: string | null;
    dismissed_at: string | null;
    has_description: boolean;
}

export interface GroupCompany {
    id: number;
    name: string;
    tier: string;
    domain: string | null;
    quality_score: number | null;
    funded_recently: boolean;
}

export interface RoleGroup {
    /** Same as `company.id`; lets infinite scroll match groups across page loads. */
    id: number;
    company: GroupCompany;
    total_matching: number;
    new_this_week: number;
    /** Every matching role id for the company, including ones beyond the loaded `roles`. */
    role_ids: number[];
    roles: RoleItem[];
}

export interface RoleFilters {
    status: 'open' | 'dismissed';
    q: string | null;
    tier: 'A' | 'B' | 'C' | null;
    workplace: 'remote' | 'hybrid' | 'on_site' | null;
    posted: '7d' | '30d' | null;
    company: number | null;
}

export interface RoleOptions {
    tiers: string[];
    workplaces: { value: string; label: string }[];
    posted: { value: string; label: string }[];
}

export interface GroupPaginator {
    data: RoleGroup[];
    current_page: number;
    last_page: number;
    total: number;
    next_page_url: string | null;
    prev_page_url: string | null;
}
