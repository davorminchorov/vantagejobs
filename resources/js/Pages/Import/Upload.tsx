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
                        Drop a CSV of companies. We'll auto-detect columns, preview the first 10 rows, and dedup on
                        website domain.
                    </p>
                </header>

                {flash?.error ? (
                    <div className="rounded-md border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive">
                        {flash.error}
                    </div>
                ) : null}

                <Card>
                    <CardHeader>
                        <CardTitle>Step 1 — Upload</CardTitle>
                        <CardDescription>
                            CSV with a header row. Up to 5,000 rows, max 1 MB. Required: a column we can map to{' '}
                            <code className="rounded bg-muted px-1">name</code>.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={submit} className="space-y-4">
                            <div className="space-y-1.5">
                                <Label htmlFor="file">CSV file</Label>
                                <Input
                                    id="file"
                                    type="file"
                                    accept=".csv,text/csv,text/plain"
                                    onChange={(e) => form.setData('file', e.target.files?.[0] ?? null)}
                                />
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

                <Card>
                    <CardHeader>
                        <CardTitle className="text-sm font-semibold uppercase tracking-wide text-muted-foreground">
                            Expected columns
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-2 text-sm">
                        <p className="text-muted-foreground">
                            We'll guess the mapping from your header names. Anything we can't guess, you'll set on the
                            next step.
                        </p>
                        <ul className="list-disc space-y-1 pl-5 text-sm">
                            <li>
                                <code className="rounded bg-muted px-1">name</code> — required (also matches{' '}
                                <code className="rounded bg-muted px-1">company</code>)
                            </li>
                            <li>
                                <code className="rounded bg-muted px-1">website</code>,{' '}
                                <code className="rounded bg-muted px-1">careers_url</code>,{' '}
                                <code className="rounded bg-muted px-1">domain</code>
                            </li>
                            <li>
                                <code className="rounded bg-muted px-1">tier</code> (A/B/C/graveyard),{' '}
                                <code className="rounded bg-muted px-1">status</code>
                            </li>
                        </ul>
                    </CardContent>
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
