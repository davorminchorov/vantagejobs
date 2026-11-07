import { Head, useForm, usePage } from '@inertiajs/react';
import { Upload } from 'lucide-react';

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/components/PageHeader';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { SectionLabel } from '@/components/ui/section-label';
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
            <div className="mx-auto flex max-w-2xl flex-col gap-8">
                <PageHeader
                    title="Import companies"
                    subtitle="Drop a CSV of companies. We'll auto-detect columns, preview the first 10 rows, and dedup on website domain."
                />

                {flash?.error ? (
                    <div
                        role="alert"
                        className="rounded-md border border-destructive/30 bg-destructive/5 px-3 py-2 text-base text-destructive sm:text-sm"
                    >
                        {flash.error}
                    </div>
                ) : null}

                <section className="flex flex-col gap-4">
                    <div className="flex flex-col gap-1">
                        <h2 className="text-base font-semibold text-balance">Step 1 — Upload</h2>
                        <p className="text-base text-pretty text-muted-foreground sm:text-sm">
                            CSV with a header row. Up to <span className="tabular-nums">5,000</span> rows, max{' '}
                            <span className="tabular-nums">1 MB</span>. Required: a column we can map to{' '}
                            <code className="rounded bg-muted px-1 font-mono">name</code>.
                        </p>
                    </div>
                    <form onSubmit={submit} className="flex flex-col gap-4">
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="file">CSV file</Label>
                            <label
                                htmlFor="file"
                                className="relative flex cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed border-border px-6 py-10 text-center hover:border-primary hover:bg-accent/30 has-[:focus-visible]:border-primary"
                            >
                                <Upload className="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                                <span className="max-w-full truncate text-base font-medium text-foreground sm:text-sm">
                                    {form.data.file ? form.data.file.name : 'Choose a CSV file or drop it here'}
                                </span>
                                {form.data.file ? (
                                    <span className="text-xs text-muted-foreground tabular-nums">
                                        {(form.data.file.size / 1024).toFixed(1)} KB
                                    </span>
                                ) : (
                                    <span className="text-base text-muted-foreground sm:text-sm">CSV up to 1 MB</span>
                                )}
                                <Input
                                    id="file"
                                    name="file"
                                    type="file"
                                    accept=".csv,text/csv,text/plain"
                                    className="sr-only"
                                    onChange={(e) => form.setData('file', e.target.files?.[0] ?? null)}
                                />
                            </label>
                            {form.errors.file ? <span className="text-sm text-destructive">{form.errors.file}</span> : null}
                        </div>
                        <div>
                            <Button type="submit" disabled={form.processing || form.data.file === null}>
                                {form.processing ? 'Parsing…' : 'Continue to mapping'}
                            </Button>
                        </div>
                    </form>
                </section>

                <section className="flex flex-col gap-3 border-t border-border pt-6">
                    <SectionLabel>Expected columns</SectionLabel>
                    <p className="text-base text-pretty text-muted-foreground sm:text-sm">
                        We&apos;ll guess the mapping from your header names. Anything we can&apos;t guess, you&apos;ll set on the next step.
                    </p>
                    <ul role="list" className="flex list-disc flex-col gap-1 pl-5 text-base sm:text-sm">
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
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
