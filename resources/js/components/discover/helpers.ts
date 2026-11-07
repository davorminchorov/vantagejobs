import type { CorrectionValue, EditableField, Listing } from './types';

export const VISIBLE_STATUS_TABS = ['new', 'kept', 'snoozed', 'dismissed'] as const;
export const MORE_STATUS_TABS = ['linked', 'auto_dismissed', 'overflow', 'duplicate', 'expired'] as const;

export function titleCaseStatus(status: string): string {
    return status
        .split('_')
        .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
        .join(' ');
}

export const PARSED_SLOT_FOR_FIELD: Record<EditableField, string> = {
    company_name: 'company_name',
    role_title: 'role_title',
    seniority: 'seniority',
    remote_type: 'remote',
    region: 'region',
    comp_min_usd: 'compensation',
    comp_max_usd: 'compensation',
    stack_tags: 'stack_tags',
};

export const AUTO_DISMISS_REASON_LABELS: Record<string, string> = {
    job_seeker: 'looks like a job seeker',
};

/** The posting's own comment on Hacker News (HN listings use the comment id as external_id). */
export function hackerNewsCommentUrl(listing: Listing): string | null {
    if (listing.source?.type !== 'hackernews' || !listing.external_id) {
        return null;
    }
    return `https://news.ycombinator.com/item?id=${encodeURIComponent(listing.external_id)}`;
}

export function hostnameFromUrl(url: string): string {
    try {
        return new URL(url).hostname.replace(/^www\./, '');
    } catch {
        return url;
    }
}

/** Short compensation label such as `$120k-$160k`, or `comp?` when unknown. */
export function compLabel(listing: Listing): string {
    if (listing.has_comp && listing.parsed_comp_min_usd != null) {
        return `$${(listing.parsed_comp_min_usd / 1000).toFixed(0)}k${
            listing.parsed_comp_max_usd != null ? `-$${(listing.parsed_comp_max_usd / 1000).toFixed(0)}k` : ''
        }`;
    }
    return 'comp?';
}

export function currentValueFor(listing: Listing, field: EditableField): CorrectionValue {
    switch (field) {
        case 'company_name':
            return listing.parsed_company_name ?? '';
        case 'role_title':
            return listing.parsed_role_title ?? '';
        case 'seniority':
            return listing.parsed_seniority ?? '';
        case 'remote_type':
            return listing.parsed_remote_type ?? 'unknown';
        case 'region':
            return listing.location_bucket ?? '';
        case 'comp_min_usd':
            return listing.parsed_comp_min_usd;
        case 'comp_max_usd':
            return listing.parsed_comp_max_usd;
        case 'stack_tags':
            return listing.stack_tags ?? [];
    }
}

const REGION_LABELS: Record<string, string> = {
    us: 'US',
    americas: 'Americas',
    emea: 'EMEA',
    apac: 'APAC',
    worldwide: 'Worldwide',
};

export function regionLabel(region: string): string {
    return REGION_LABELS[region] ?? region;
}
