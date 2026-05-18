import { Head, Link } from '@inertiajs/react';

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';

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

function EmptyState({ label }: { label: string }) {
    return (
        <p className="text-sm text-muted-foreground">
            No compensation samples yet for {label}.
        </p>
    );
}

function BucketTable({ rows }: { rows: BucketRow[] }) {
    if (rows.length === 0) {
        return <EmptyState label="this breakdown" />;
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
                {rows.map((r) => (
                    <TableRow key={r.label}>
                        <TableCell className="font-medium">{r.label}</TableCell>
                        <TableCell className="text-right tabular-nums">{r.sample_count}</TableCell>
                        <TableCell className="text-right tabular-nums">{fmtUsd(r.p25)}</TableCell>
                        <TableCell className="text-right tabular-nums">{fmtUsd(r.median)}</TableCell>
                        <TableCell className="text-right tabular-nums">{fmtUsd(r.p75)}</TableCell>
                    </TableRow>
                ))}
            </TableBody>
        </Table>
    );
}

export default function CompInsightsPage({ byRoleFamily, byGeography, byCompany }: Props) {
    return (
        <AuthenticatedLayout>
            <Head title="Compensation insights" />
            <div className="space-y-6">
                <header>
                    <h1 className="text-2xl font-semibold tracking-tight">Compensation insights</h1>
                    <p className="text-sm text-muted-foreground">
                        Aggregated midpoint USD compensation across parsed listings. Excludes
                        dismissed, duplicate, and auto-dismissed listings.
                    </p>
                </header>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-sm font-semibold uppercase tracking-wide text-muted-foreground">
                            By role family
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <BucketTable rows={byRoleFamily} />
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-sm font-semibold uppercase tracking-wide text-muted-foreground">
                            By geography
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <BucketTable rows={byGeography} />
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-sm font-semibold uppercase tracking-wide text-muted-foreground">
                            By company (≥ 3 samples)
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {byCompany.length === 0 ? (
                            <EmptyState label="any company yet" />
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
                                            <TableCell className="font-medium">
                                                <Link
                                                    href={`/companies/${r.company_id}`}
                                                    className="hover:underline"
                                                >
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
                    </CardContent>
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
