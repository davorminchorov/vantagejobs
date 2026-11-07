import { Head, Link } from '@inertiajs/react';

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import InsightsNav from '@/components/insights/InsightsNav';
import PageHeader from '@/components/PageHeader';
import { EmptyState } from '@/components/ui/empty-state';
import { SectionLabel } from '@/components/ui/section-label';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';

interface BucketRow {
    label: string;
    sample_count: number;
    p25: number | null;
    median: number | null;
    p75: number | null;
}

interface CompanyRow {
    company_id: number;
    company_name: string;
    sample_count: number;
    p25: number | null;
    median: number | null;
    p75: number | null;
}

interface Props {
    byRoleFamily: BucketRow[];
    byGeography: BucketRow[];
    byCompany: CompanyRow[];
}

function fmtUsd(value: number | null): string {
    if (value === null || Number.isNaN(value)) {
        return '—';
    }
    const k = Math.round(value / 1000);
    return `$${k}k`;
}

const UNCLASSIFIED_LABEL = 'Unclassified';

/**
 * Sorts the "Unclassified" bucket to the end while preserving the backend's
 * sample_count-descending order among the tracked buckets.
 */
function sortUnclassifiedLast(rows: BucketRow[]): BucketRow[] {
    return [...rows].sort((a, b) => {
        if (a.label === UNCLASSIFIED_LABEL && b.label !== UNCLASSIFIED_LABEL) return 1;
        if (b.label === UNCLASSIFIED_LABEL && a.label !== UNCLASSIFIED_LABEL) return -1;
        return 0;
    });
}

function BucketTable({ rows, unclassifyOther = false }: { rows: BucketRow[]; unclassifyOther?: boolean }) {
    const sortedRows = unclassifyOther ? sortUnclassifiedLast(rows) : rows;

    if (sortedRows.length === 0) {
        return <EmptyState>No compensation samples yet for this breakdown.</EmptyState>;
    }

    return (
        <Table>
            <TableHeader>
                <TableRow>
                    <TableHead>Bucket</TableHead>
                    <TableHead className="text-right">Samples</TableHead>
                    <TableHead className="text-right">P25</TableHead>
                    <TableHead className="text-right">Median</TableHead>
                    <TableHead className="text-right">P75</TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                {sortedRows.map((r) => {
                    const isUnclassified = unclassifyOther && r.label === UNCLASSIFIED_LABEL;
                    return (
                        <TableRow key={r.label}>
                            <TableCell
                                className={cn(
                                    'font-medium text-foreground',
                                    isUnclassified && 'font-normal text-muted-foreground',
                                )}
                            >
                                {isUnclassified ? `Unclassified (${r.sample_count} samples)` : r.label}
                            </TableCell>
                            <TableCell className="text-right tabular-nums">{r.sample_count}</TableCell>
                            <TableCell className="text-right tabular-nums">{fmtUsd(r.p25)}</TableCell>
                            <TableCell className="text-right tabular-nums">{fmtUsd(r.median)}</TableCell>
                            <TableCell className="text-right tabular-nums">{fmtUsd(r.p75)}</TableCell>
                        </TableRow>
                    );
                })}
            </TableBody>
        </Table>
    );
}

export default function CompInsightsPage({ byRoleFamily, byGeography, byCompany }: Props) {
    return (
        <AuthenticatedLayout>
            <Head title="Compensation insights" />
            <div className="flex flex-col gap-6">
                <PageHeader
                    title="Compensation insights"
                    subtitle="Aggregated midpoint USD compensation across parsed listings. Excludes dismissed, duplicate, and auto-dismissed listings."
                />

                <InsightsNav />

                <section className="flex flex-col gap-3">
                    <SectionLabel>By role family</SectionLabel>
                    <BucketTable rows={byRoleFamily} unclassifyOther />
                    <p className="text-xs text-pretty text-muted-foreground">
                        Bucketing covers tracked role families only; everything else lands in Unclassified.
                    </p>
                </section>

                <section className="flex flex-col gap-3 border-t border-border pt-6">
                    <SectionLabel>By geography</SectionLabel>
                    <BucketTable rows={byGeography} unclassifyOther />
                </section>

                <section className="flex flex-col gap-3 border-t border-border pt-6">
                    <SectionLabel>By company (≥ 3 samples)</SectionLabel>
                    {byCompany.length === 0 ? (
                        <EmptyState>No compensation samples yet for any company.</EmptyState>
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Company</TableHead>
                                    <TableHead className="text-right">Samples</TableHead>
                                    <TableHead className="text-right">P25</TableHead>
                                    <TableHead className="text-right">Median</TableHead>
                                    <TableHead className="text-right">P75</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {byCompany.map((r) => (
                                    <TableRow key={r.company_id}>
                                        <TableCell className="font-medium text-foreground">
                                            <Link href={`/companies/${r.company_id}`} className="text-primary hover:underline">
                                                {r.company_name}
                                            </Link>
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">{r.sample_count}</TableCell>
                                        <TableCell className="text-right tabular-nums">{fmtUsd(r.p25)}</TableCell>
                                        <TableCell className="text-right tabular-nums">{fmtUsd(r.median)}</TableCell>
                                        <TableCell className="text-right tabular-nums">{fmtUsd(r.p75)}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
