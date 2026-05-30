import { Head, useForm, usePage } from '@inertiajs/react';

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { PageProps } from '@/types';

interface UploadFormData {
    file: File | null;
}

export default function ImportUploadPage() {
    const { flash } = usePage<PageProps>().props;
    const form = useForm<UploadFormData>({ file: null });

    function submit(e: React.FormEvent) {
        e.preventDefault();
        form.post('/import', { forceFormData: true });
    }

    return (
        <AuthenticatedLayout>
            <Head title="Import" />
            <div className="mx-auto max-w-2xl space-y-6">
                <header>
                    <h1 className="text-2xl font-semibold tracking-tight">Import companies</h1>
                    <p className="text-sm text-muted-foreground">
                        Drop a CSV of companies. We&apos;ll auto-detect columns, preview the first 10 rows, and dedup on
                        website domain.
                    </p>
                </header>

                {flash?.error ? (
                    <div className="rounded-md border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive">
                        {flash.error}
                    </div>
                ) : null}

                <Card className="rounded-2xl border-border bg-card shadow-sm">
                    <CardHeader>
                        <CardTitle>Step 1 — Upload</CardTitle>
                        <CardDescription>
                            CSV with a header row. Up to{' '}
                            <span className="font-mono tabular-nums">5,000</span> rows, max{' '}
                            <span className="font-mono tabular-nums">1 MB</span>. Required: a column we can map to{' '}
                            <code className="rounded bg-muted px-1 font-mono">name</code>.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={submit} className="space-y-4">
                            <div className="space-y-1.5">
                                <Label htmlFor="file">CSV file</Label>
                                <label
                                    htmlFor="file"
                                    className="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-2xl border-2 border-dashed border-border bg-muted/30 px-6 py-10 text-center transition-colors hover:border-primary hover:bg-accent/30"
                                >
                                    <span className="text-sm font-medium text-foreground">
                                        {form.data.file ? form.data.file.name : 'Choose a CSV file or drop it here'}
                                    </span>
                                    {form.data.file ? (
                                        <span className="font-mono text-xs tabular-nums text-muted-foreground">
                                            {(form.data.file.size / 1024).toFixed(1)} KB
                                        </span>
                                    ) : (
                                        <span className="text-sm text-muted-foreground">CSV up to 1 MB</span>
                                    )}
                                    <Input
                                        id="file"
                                        type="file"
                                        accept=".csv,text/csv,text/plain"
                                        className="sr-only"
                                        onChange={(e) => form.setData('file', e.target.files?.[0] ?? null)}
                                    />
                                </label>
                                {form.errors.file ? (
                                    <span className="text-xs text-destructive">{form.errors.file}</span>
                                ) : null}
                            </div>
                            <Button type="submit" disabled={form.processing || form.data.file === null}>
                                {form.processing ? 'Parsing…' : 'Continue to mapping'}
                            </Button>
                        </form>
                    </CardContent>
                </Card>

                <Card className="rounded-2xl border-border bg-card shadow-sm">
                    <CardHeader>
                        <CardTitle className="font-mono text-sm font-semibold uppercase tracking-wide text-muted-foreground">
                            Expected columns
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-2 text-sm">
                        <p className="text-muted-foreground">
                            We&apos;ll guess the mapping from your header names. Anything we can&apos;t guess, you&apos;ll set on the
                            next step.
                        </p>
                        <ul className="list-disc space-y-1 pl-5 text-sm">
                            <li>
                                <code className="rounded bg-muted px-1 font-mono">name</code> — required (also matches{' '}
                                <code className="rounded bg-muted px-1 font-mono">company</code>)
                            </li>
                            <li>
                                <code className="rounded bg-muted px-1 font-mono">website</code>,{' '}
                                <code className="rounded bg-muted px-1 font-mono">careers_url</code>,{' '}
                                <code className="rounded bg-muted px-1 font-mono">domain</code>
                            </li>
                            <li>
                                <code className="rounded bg-muted px-1 font-mono">tier</code> (A/B/C/graveyard),{' '}
                                <code className="rounded bg-muted px-1 font-mono">status</code>
                            </li>
                        </ul>
                    </CardContent>
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
