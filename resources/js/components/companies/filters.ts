export interface CompanyFilters {
    tier: string[];
    status: string[];
    ats_type: string[];
    stage: string[];
    size: string[];
    remote_policy: string[];
    primary_stack: string[];
    has_open_roles: boolean | null;
    last_checked: string | null;
    domain: string | null;
    q: string | null;
    recently_funded: boolean | null;
    new_this_week: boolean;
    unscraped: boolean;
    sort: string;
}

export interface CompanyFilterOptions {
    tiers: string[];
    statuses: string[];
    ats_types: string[];
    last_checked: string[];
    sorts: string[];
    stages: string[];
    sizes: string[];
    remote_policies: string[];
    primary_stacks: string[];
}

export const DEFAULT_SORT = 'newest_posting';

export const EMPTY_FILTERS: CompanyFilters = {
    tier: [],
    status: [],
    ats_type: [],
    stage: [],
    size: [],
    remote_policy: [],
    primary_stack: [],
    has_open_roles: null,
    last_checked: null,
    domain: null,
    q: null,
    recently_funded: null,
    new_this_week: false,
    unscraped: false,
    sort: DEFAULT_SORT,
};

export const LAST_CHECKED_LABELS: Record<string, string> = {
    never: 'Never',
    last_24h: 'Last 24h',
    last_7d: 'Last 7 days',
    older_than_7d: 'Older than 7 days',
    last_30d: 'Last 30 days',
    older_than_30d: 'Older than 30 days',
};

export const SORT_LABELS: Record<string, string> = {
    newest_posting: 'Newest posting',
    tier: 'Tier',
    name: 'Name',
    new_this_week: 'New this week',
    quality: 'Quality',
};

export function humanize(value: string): string {
    return value.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

export function tierLabel(tier: string): string {
    return tier === 'graveyard' ? 'Graveyard' : `Tier ${tier}`;
}

export function companyFiltersToQuery(filters: CompanyFilters): Record<string, string | string[]> {
    const params: Record<string, string | string[]> = {};
    if (filters.tier.length > 0) params.tier = filters.tier;
    if (filters.status.length > 0) params.status = filters.status;
    if (filters.ats_type.length > 0) params.ats_type = filters.ats_type;
    if (filters.stage.length > 0) params.stage = filters.stage;
    if (filters.size.length > 0) params.size = filters.size;
    if (filters.remote_policy.length > 0) params.remote_policy = filters.remote_policy;
    if (filters.primary_stack.length > 0) params.primary_stack = filters.primary_stack;
    if (filters.has_open_roles !== null) params.has_open_roles = filters.has_open_roles ? '1' : '0';
    if (filters.last_checked) params.last_checked = filters.last_checked;
    if (filters.domain) params.domain = filters.domain;
    if (filters.q) params.q = filters.q;
    if (filters.recently_funded !== null) params.recently_funded = filters.recently_funded ? '1' : '0';
    if (filters.new_this_week) params.new_this_week = '1';
    if (filters.unscraped) params.unscraped = '1';
    if (filters.sort && filters.sort !== DEFAULT_SORT) params.sort = filters.sort;
    return params;
}

export function hasActiveFilters(filters: CompanyFilters): boolean {
    return (
        filters.tier.length > 0 ||
        filters.status.length > 0 ||
        filters.ats_type.length > 0 ||
        filters.stage.length > 0 ||
        filters.size.length > 0 ||
        filters.remote_policy.length > 0 ||
        filters.primary_stack.length > 0 ||
        filters.has_open_roles !== null ||
        filters.last_checked !== null ||
        filters.domain !== null ||
        filters.q !== null ||
        filters.recently_funded !== null ||
        filters.new_this_week ||
        filters.unscraped
    );
}

export function toggleArrayValue(current: string[], value: string): string[] {
    return current.includes(value) ? current.filter((v) => v !== value) : [...current, value];
}

export interface ActiveChip {
    key: string;
    label: string;
    patch: Partial<CompanyFilters>;
}

export function activeChips(filters: CompanyFilters): ActiveChip[] {
    const chips: ActiveChip[] = [];
    for (const t of filters.tier) {
        chips.push({ key: `tier:${t}`, label: tierLabel(t), patch: { tier: filters.tier.filter((v) => v !== t) } });
    }
    for (const s of filters.status) {
        chips.push({ key: `status:${s}`, label: `Status: ${humanize(s)}`, patch: { status: filters.status.filter((v) => v !== s) } });
    }
    for (const a of filters.ats_type) {
        chips.push({ key: `ats:${a}`, label: `ATS: ${humanize(a)}`, patch: { ats_type: filters.ats_type.filter((v) => v !== a) } });
    }
    for (const s of filters.stage) {
        chips.push({ key: `stage:${s}`, label: `Stage: ${s}`, patch: { stage: filters.stage.filter((v) => v !== s) } });
    }
    if (filters.has_open_roles !== null) {
        chips.push({
            key: 'has_open_roles',
            label: filters.has_open_roles ? 'Hiring now' : 'No open roles',
            patch: { has_open_roles: null },
        });
    }
    if (filters.new_this_week) {
        chips.push({ key: 'new_this_week', label: 'New this week', patch: { new_this_week: false } });
    }
    if (filters.recently_funded !== null) {
        chips.push({
            key: 'recently_funded',
            label: filters.recently_funded ? 'Funded recently' : 'Not funded recently',
            patch: { recently_funded: null },
        });
    }
    if (filters.unscraped) {
        chips.push({ key: 'unscraped', label: 'Not being scraped', patch: { unscraped: false } });
    }
    if (filters.last_checked) {
        chips.push({
            key: 'last_checked',
            label:
                filters.last_checked === 'older_than_7d'
                    ? 'Stale'
                    : `Checked: ${LAST_CHECKED_LABELS[filters.last_checked] ?? filters.last_checked}`,
            patch: { last_checked: null },
        });
    }
    if (filters.domain) {
        chips.push({ key: 'domain', label: `Domain: ${filters.domain}`, patch: { domain: null } });
    }
    if (filters.q) {
        chips.push({ key: 'q', label: `Search: ${filters.q}`, patch: { q: null } });
    }
    return chips;
}
