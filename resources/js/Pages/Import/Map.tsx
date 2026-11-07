import { Head, router, useForm } from '@inertiajs/react';

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/components/PageHeader';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { NativeSelect } from '@/components/ui/native-select';

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
            <div className="flex flex-col gap-8">
                <PageHeader
                    title="Step 2 — Map columns"
                    subtitle={
                        <>
                            <span className="tabular-nums">{row_count.toLocaleString()}</span> rows
                            {original_name ? ` from ${original_name}` : ''}. Tell us which CSV column feeds each field.
                        </>
                    }
                    actions={
                        <Button type="button" variant="ghost" onClick={cancel}>
                            Start over
                        </Button>
                    }
                />

                <form onSubmit={submit} className="flex flex-col gap-8">
                    <section className="flex flex-col gap-4">
                        <div className="flex flex-col gap-1">
                            <h2 className="text-base font-semibold text-balance">Column mapping</h2>
                            <p className="text-base text-pretty text-muted-foreground sm:text-sm">
                                Required fields are marked with <span className="text-destructive">*</span>. We applied a
                                best-guess match from your headers.
                            </p>
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            {fields.map((field) => {
                                const required = required_fields.includes(field);
                                const value = form.data.mapping[field] ?? '';
                                return (
                                    <div key={field} className="flex min-w-0 flex-col gap-1.5">
                                        <Label htmlFor={`map-${field}`}>
                                            {humanize(field)}
                                            {required ? <span className="text-destructive"> *</span> : null}
                                        </Label>
                                        <NativeSelect
                                            id={`map-${field}`}
                                            name={`mapping[${field}]`}
                                            value={value}
                                            onChange={(e) =>
                                                form.setData('mapping', {
                                                    ...form.data.mapping,
                                                    [field]: e.target.value === '' ? null : e.target.value,
                                                })
                                            }
                                            className="h-11 focus-visible:outline-0 focus-visible:ring-2 focus-visible:ring-ring"
                                        >
                                            <option value="">— Skip —</option>
                                            {headers.map((header) => (
                                                <option key={header} value={header}>
                                                    {header}
                                                </option>
                                            ))}
                                        </NativeSelect>
                                        {form.errors[`mapping.${field}` as keyof typeof form.errors] ? (
                                            <span className="text-sm text-destructive">
                                                {form.errors[`mapping.${field}` as keyof typeof form.errors] as string}
                                            </span>
                                        ) : null}
                                    </div>
                                );
                            })}
                        </div>
                    </section>

                    <section className="flex flex-col gap-3 border-t border-border pt-6">
                        <div className="flex flex-col gap-1">
                            <h2 className="text-base font-semibold text-balance">Preview</h2>
                            <p className="text-base text-pretty text-muted-foreground sm:text-sm">
                                First <span className="tabular-nums">{preview.length}</span> mapped rows.
                            </p>
                        </div>
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
                                                    <span className="font-mono">{row[field]}</span>
                                                ) : (
                                                    <span className="text-muted-foreground">—</span>
                                                )}
                                            </TableCell>
                                        ))}
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </section>

                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
                        <Button type="submit" disabled={form.processing || missingRequired.length > 0}>
                            {form.processing ? (
                                'Importing…'
                            ) : (
                                <>
                                    Import <span className="tabular-nums">{row_count.toLocaleString()}</span> rows
                                </>
                            )}
                        </Button>
                        {missingRequired.length > 0 ? (
                            <span className="text-base text-destructive sm:text-sm">
                                Map {missingRequired.map(humanize).join(', ')} to continue.
                            </span>
                        ) : (
                            <span className="text-base text-pretty text-muted-foreground sm:text-sm">
                                Duplicates by domain will be skipped. The action is reversible row-by-row from Companies.
                            </span>
                        )}
                    </div>
                </form>
            </div>
        </AuthenticatedLayout>
    );
}
