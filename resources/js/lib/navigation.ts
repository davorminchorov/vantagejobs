/**
 * Shared primary-navigation destinations, kept in one module so both the
 * authenticated layout (top nav, mobile sheet, avatar menu) and the global
 * command palette (D4 "Pages" group) render the same list without a
 * circular import between the two.
 */

export interface NavLinkItem {
    readonly name: string;
    readonly href: string;
}

export const TOP_LEVEL_ITEMS: ReadonlyArray<NavLinkItem> = [
    { name: 'Dashboard', href: '/dashboard' },
    { name: 'Discover', href: '/discover' },
    { name: 'Companies', href: '/companies' },
    { name: 'Bookmarks', href: '/bookmarks' },
];

export const TRACKER_ITEMS: ReadonlyArray<NavLinkItem> = [
    { name: 'Pipeline', href: '/pipeline' },
    { name: 'Contacts', href: '/contacts' },
    { name: 'Follow-ups', href: '/follow-ups' },
    { name: 'Templates', href: '/templates' },
    { name: 'Calendar', href: '/calendar' },
];

export const INSIGHTS_ITEMS: ReadonlyArray<NavLinkItem> = [
    { name: 'Hiring trends', href: '/insights/hiring' },
    { name: 'Compensation', href: '/insights/comp' },
    { name: 'Funding candidates', href: '/insights/funding-candidates' },
];

export const SETTINGS_ITEM: NavLinkItem = { name: 'Settings', href: '/settings/profile' };

export const IMPORT_ITEM: NavLinkItem = { name: 'Import', href: '/import' };

/** Runs/Audit/Parser require the is_admin gate; Settings itself does not. */
export const ADMIN_ONLY_ITEMS: ReadonlyArray<NavLinkItem> = [
    { name: 'Runs', href: '/admin/runs' },
    { name: 'Audit', href: '/admin/audit' },
    { name: 'Parser', href: '/admin/parser' },
];
