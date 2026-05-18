import { Head, router, useForm } from '@inertiajs/react';

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { cn } from '@/lib/utils';

type Mapping = Record<string, string | null>;

interface Props {
    headers: string[];
    mapping: Mapping;
    preview: Array<Record<string, string | null>>;
    row_count: number;
    original_name: string | null;
    fields: string[];
    required_fields: string[];
}

function humanize(value: string): string {
    return value.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

export default function ImportMapPage({ headers, mapping, preview, row_count, original_name, fields, required_fields }: Props) {
    const form = useForm<{ mapping: Mapping }>({ mapping });

    function submit(e: React.FormEvent) {
        e.preventDefault();
        form.post('/import/run');
    }

    function cancel() {
        router.post('/import/cancel');
    }

    const missingRequired = required_fields.filter((f) => !form.data.mapping[f]);

    return (
        <AuthenticatedLayout>
            <Head title="Import — Map columns" />
            <div className="space-y-6">
                <header className="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">Step 2 — Map columns</h1>
                        <p className="text-sm text-muted-foreground">
                            {row_count.toLocaleString()} rows
                            {original_name ? ` from ${original_name}` : ''}. Tell us which CSV column feeds each field.
                        </p>
                    </div>
                    <Button type="button" variant="ghost" onClick={cancel}>
                        Start over
                    </Button>
                </header>

                <form onSubmit={submit} className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle>Column mapping</CardTitle>
                            <CardDescription>
                                Required fields are marked with <span className="text-destructive">*</span>. We applied
                                a best-guess match from your headers.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="grid gap-4 sm:grid-cols-2">
                                {fields.map((field) => {
                                    const required = required_fields.includes(field);
                                    const value = form.data.mapping[field] ?? '';
                                    return (
                                        <div key={field} className="space-y-1.5">
                                            <Label htmlFor={`map-${field}`}>
                                                {humanize(field)}
                                                {required ? <span className="text-destructive"> *</span> : null}
                                            </Label>
                                            <select
                                                id={`map-${field}`}
                                                value={value}
                                                onChange={(e) =>
                                                    form.setData('mapping', {
                                                        ...form.data.mapping,
                                                        [field]: e.target.value === '' ? null : e.target.value,
                                                    })
                                                }
                                                className={cn(
                                                    'h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm',
                                                    'focus-visible:ring-ring focus-visible:outline-none focus-visible:ring-2',
                                                )}
                                            >
                                                <option value="">— Skip —</option>
                                                {headers.map((header) => (
                                                    <option key={header} value={header}>
                                                        {header}
                                                    </option>
                                                ))}
                                            </select>
                                            {form.errors[`mapping.${field}` as keyof typeof form.errors] ? (
                                                <span className="text-xs text-destructive">
                                                    {form.errors[`mapping.${field}` as keyof typeof form.errors] as string}
                                                </span>
                                            ) : null}
                                        </div>
                                    );
                                })}
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Preview</CardTitle>
                            <CardDescription>First {preview.length} mapped rows.</CardDescription>
                        </CardHeader>
                        <CardContent className="p-0">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        {fields.map((field) => (
                                            <TableHead key={field}>{humanize(field)}</TableHead>
                                        ))}
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {preview.map((row, index) => (
                                        <TableRow key={index}>
                                            {fields.map((field) => (
                                                <TableCell key={field}>
                                                    {row[field] ? (
                                                        <span className="text-sm">{row[field]}</span>
                                                    ) : (
                                                        <span className="text-xs text-muted-foreground">—</span>
                                                    )}
                                                </TableCell>
                                            ))}
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>

                    <div className="flex items-center gap-3">
                        <Button type="submit" disabled={form.processing || missingRequired.length > 0}>
                            {form.processing ? 'Importing…' : `Import ${row_count.toLocaleString()} rows`}
                        </Button>
                        {missingRequired.length > 0 ? (
                            <span className="text-xs text-destructive">
                                Map {missingRequired.map(humanize).join(', ')} to continue.
                            </span>
                        ) : (
                            <span className="text-xs text-muted-foreground">
                                Duplicates by domain will be skipped. The action is reversible row-by-row from Companies.
                            </span>
                        )}
                    </div>
                </form>
            </div>
        </AuthenticatedLayout>
    );
}
