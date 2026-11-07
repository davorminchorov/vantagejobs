import { Link } from '@inertiajs/react';
import { ArrowDown, ArrowUp } from 'lucide-react';

import Sparkline from '@/components/companies/Sparkline';
import { Badge } from '@/components/ui/badge';
import { NativeSelect } from '@/components/ui/native-select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { cn } from '@/lib/utils';

import { ChangeValue } from './ChangeValue';
import type { CompanyMomentum, SortKey, SortState } from './types';

const COLUMNS: ReadonlyArray<{ key: SortKey; label: string; numeric: boolean }> = [
    { key: 'name', label: 'Company', numeric: false },
    { key: 'open_now', label: 'Open', numeric: true },
    { key: 'change_7d', label: '7 days', numeric: true },
    { key: 'change_30d', label: '30 days', numeric: true },
    { key: 'new_7d', label: 'Opened 7d', numeric: true },
    { key: 'closed_7d', label: 'Closed 7d', numeric: true },
];

const MOBILE_SORTS: ReadonlyArray<{ value: string; label: string; sort: SortState }> = [
    { value: 'change_30d:desc', label: 'Growing most, 30 days', sort: { key: 'change_30d', direction: 'desc' } },
    { value: 'change_30d:asc', label: 'Shrinking most, 30 days', sort: { key: 'change_30d', direction: 'asc' } },
    { value: 'change_7d:desc', label: 'Growing most, 7 days', sort: { key: 'change_7d', direction: 'desc' } },
    { value: 'change_7d:asc', label: 'Shrinking most, 7 days', sort: { key: 'change_7d', direction: 'asc' } },
    { value: 'open_now:desc', label: 'Most open roles', sort: { key: 'open_now', direction: 'desc' } },
    { value: 'new_7d:desc', label: 'Most opened this week', sort: { key: 'new_7d', direction: 'desc' } },
    { value: 'name:asc', label: 'Name', sort: { key: 'name', direction: 'asc' } },
];

function TierBadge({ tier }: { tier: string }) {
    return <Badge variant={tier === 'A' ? 'default' : tier === 'B' ? 'secondary' : 'outline'}>Tier {tier}</Badge>;
}

export interface MomentumTableProps {
    companies: CompanyMomentum[];
    sort: SortState;
    onSort: (sort: SortState) => void;
}

/** Companies ranked by hiring momentum: sortable table on wide screens, a list with a sort menu on phones. */
export function MomentumTable({ companies, sort, onSort }: MomentumTableProps) {
    const toggle = (key: SortKey) =>
        onSort(
            sort.key === key
                ? { key, direction: sort.direction === 'asc' ? 'desc' : 'asc' }
                : { key, direction: key === 'name' ? 'asc' : 'desc' },
        );

    return (
        <>
            <div className="hidden md:block">
                <Table>
                    <TableHeader>
                        <TableRow>
                            {COLUMNS.slice(0, 2).map((column) => (
                                <SortableHead key={column.key} column={column} sort={sort} onToggle={toggle} />
                            ))}
                            <TableHead>Last 90 days</TableHead>
                            {COLUMNS.slice(2).map((column) => (
                                <SortableHead key={column.key} column={column} sort={sort} onToggle={toggle} />
                            ))}
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {companies.map((company) => (
                            <TableRow key={company.id}>
                                <TableCell>
                                    <div className="flex items-center gap-2">
                                        <Link href={`/companies/${company.id}`} className="font-medium hover:underline">
                                            {company.name}
                                        </Link>
                                        <TierBadge tier={company.tier} />
                                    </div>
                                </TableCell>
                                <TableCell className="text-right tabular-nums">{company.open_now.toLocaleString()}</TableCell>
                                <TableCell>
                                    <Sparkline values={company.trend} label={`${company.name} open roles over 90 days`} />
                                </TableCell>
                                <TableCell className="text-right">
                                    <ChangeValue value={company.change_7d} period="7 days" />
                                </TableCell>
                                <TableCell className="text-right">
                                    <ChangeValue value={company.change_30d} period="30 days" />
                                </TableCell>
                                <TableCell className="text-right tabular-nums">{company.new_7d.toLocaleString()}</TableCell>
                                <TableCell className="text-right tabular-nums">{company.closed_7d.toLocaleString()}</TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </div>

            <div className="flex flex-col gap-3 md:hidden">
                <label className="flex items-center gap-2 text-base text-muted-foreground">
                    Sort
                    <NativeSelect
                        wrapperClassName="flex-1"
                        value={`${sort.key}:${sort.direction}`}
                        onChange={(event) => {
                            const choice = MOBILE_SORTS.find((option) => option.value === event.target.value);
                            if (choice) {
                                onSort(choice.sort);
                            }
                        }}
                    >
                        {MOBILE_SORTS.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                        {MOBILE_SORTS.some((option) => option.value === `${sort.key}:${sort.direction}`) ? null : (
                            <option value={`${sort.key}:${sort.direction}`}>Custom</option>
                        )}
                    </NativeSelect>
                </label>
                <ul role="list" className="divide-y divide-border border-y border-border">
                    {companies.map((company) => (
                        <li key={company.id} className="flex flex-col gap-2 py-3">
                            <div className="flex items-center gap-2">
                                <Link href={`/companies/${company.id}`} className="min-w-0 truncate font-medium hover:underline">
                                    {company.name}
                                </Link>
                                <TierBadge tier={company.tier} />
                                <Sparkline
                                    values={company.trend}
                                    label={`${company.name} open roles over 90 days`}
                                    className="ml-auto w-20 shrink-0"
                                />
                            </div>
                            <div className="flex flex-wrap gap-x-4 gap-y-1 text-base whitespace-nowrap tabular-nums">
                                <span>
                                    <span className="text-muted-foreground">Open </span>
                                    {company.open_now.toLocaleString()}
                                </span>
                                <span>
                                    <span className="text-muted-foreground">7d </span>
                                    <ChangeValue value={company.change_7d} period="7 days" />
                                </span>
                                <span>
                                    <span className="text-muted-foreground">30d </span>
                                    <ChangeValue value={company.change_30d} period="30 days" />
                                </span>
                            </div>
                        </li>
                    ))}
                </ul>
            </div>
        </>
    );
}

function SortableHead({ column, sort, onToggle }: { column: (typeof COLUMNS)[number]; sort: SortState; onToggle: (key: SortKey) => void }) {
    const active = sort.key === column.key;
    const Icon = sort.direction === 'asc' ? ArrowUp : ArrowDown;

    return (
        <TableHead
            className={cn(column.numeric && 'text-right')}
            aria-sort={active ? (sort.direction === 'asc' ? 'ascending' : 'descending') : 'none'}
        >
            <button
                type="button"
                onClick={() => onToggle(column.key)}
                className={cn(
                    'inline-flex items-center gap-1 font-medium hover:text-foreground',
                    active ? 'text-foreground' : 'text-muted-foreground',
                    column.numeric && 'flex-row-reverse',
                )}
            >
                {column.label}
                <Icon aria-hidden="true" className={cn('size-3.5', active ? 'opacity-100' : 'opacity-0')} />
            </button>
        </TableHead>
    );
}
