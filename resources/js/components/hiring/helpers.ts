import type { CompanyMomentum, SortState } from './types';

/** "+12", "-3", "0". */
export function signed(value: number): string {
    if (value > 0) {
        return `+${value.toLocaleString()}`;
    }
    if (value < 0) {
        return `−${Math.abs(value).toLocaleString()}`;
    }
    return '0';
}

/** Sorts a copy; companies without a value for the key always go last. */
export function sortCompanies(companies: CompanyMomentum[], sort: SortState): CompanyMomentum[] {
    const factor = sort.direction === 'asc' ? 1 : -1;

    return [...companies].sort((a, b) => {
        if (sort.key === 'name') {
            return factor * a.name.localeCompare(b.name);
        }
        const left = a[sort.key];
        const right = b[sort.key];
        if (left === null && right === null) {
            return a.name.localeCompare(b.name);
        }
        if (left === null) {
            return 1;
        }
        if (right === null) {
            return -1;
        }
        return factor * (left - right) || a.name.localeCompare(b.name);
    });
}
