import type { RoleItem } from './types';

/** The role's posting URL when it is http(s). Scraped values never reach an href otherwise. */
export function rolePostingUrl(role: RoleItem): string | null {
    if (!role.url) {
        return null;
    }
    try {
        const parsed = new URL(role.url);
        return parsed.protocol === 'http:' || parsed.protocol === 'https:' ? parsed.href : null;
    } catch {
        return null;
    }
}

const WORKPLACE_LABELS: Record<string, string> = {
    remote: 'Remote',
    hybrid: 'Hybrid',
    on_site: 'On-site',
};

export function workplaceLabel(role: RoleItem): string | null {
    if (!role.workplace_type || role.workplace_type === 'unspecified') {
        return null;
    }
    return WORKPLACE_LABELS[role.workplace_type] ?? role.workplace_type;
}

function compactAmount(value: number): string {
    return value >= 1000 ? `${Math.round(value / 1000)}k` : String(value);
}

/** Short pay label such as `EUR 70k-90k / year`, or null when the posting has no pay. */
export function rolePayLabel(role: RoleItem): string | null {
    if (role.comp_min === null && role.comp_max === null) {
        return null;
    }
    const range =
        role.comp_min !== null && role.comp_max !== null && role.comp_min !== role.comp_max
            ? `${compactAmount(role.comp_min)}-${compactAmount(role.comp_max)}`
            : compactAmount((role.comp_min ?? role.comp_max) as number);
    const interval = role.comp_interval ? ` / ${role.comp_interval.replace(/_/g, ' ')}` : '';
    return `${role.comp_currency ? `${role.comp_currency} ` : ''}${range}${interval}`;
}

/** The date a role is ordered by: when it was posted, or first seen when the source has no date. */
export function roleDate(role: RoleItem): string {
    return role.posted_at ?? role.created_at;
}

export function sourceLabel(source: string): string {
    const name = source.startsWith('aggregator:') ? source.slice('aggregator:'.length) : source;
    return name.charAt(0).toUpperCase() + name.slice(1);
}
