import { useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { SectionLabel } from '@/components/ui/section-label';
import { NativeSelect } from '@/components/ui/native-select';

export interface CompanyFormValues {
    name: string;
    website: string;
    careers_url: string;
    domain: string;
    ats_type: string;
    ats_slug: string;
    ats_host: string;
    tier: string;
    status: string;
    /** Set when tracking a company from Insights > Recently funded. */
    funding_announcement_id: number | null;
}

export interface CompanyFormOptions {
    tiers: string[];
    statuses: string[];
    ats_types: string[];
}

interface CompanyFormProps {
    mode: 'create' | 'edit';
    initial: Partial<CompanyFormValues>;
    options: CompanyFormOptions;
    action: string;
    method?: 'post' | 'put';
    cancelHref: string;
}

const EMPTY: CompanyFormValues = {
    name: '',
    website: '',
    careers_url: '',
    domain: '',
    ats_type: '',
    ats_slug: '',
    ats_host: '',
    tier: 'C',
    status: 'researching',
    funding_announcement_id: null,
};

function humanize(value: string): string {
    return value.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

function csrfToken(): string {
    const el = document.querySelector('meta[name="csrf-token"]');
    return el?.getAttribute('content') ?? '';
}

export default function CompanyForm({ mode, initial, options, action, method = 'post', cancelHref }: CompanyFormProps) {
    const form = useForm<CompanyFormValues>({ ...EMPTY, ...initial });
    const [detecting, setDetecting] = useState(false);
    const [detectionHint, setDetectionHint] = useState<string | null>(null);
    const detectAbort = useRef<AbortController | null>(null);

    const websiteForDetection = form.data.careers_url || form.data.website;

    useEffect(() => {
        if (!websiteForDetection || websiteForDetection.length < 8) {
            return;
        }
        if (form.data.ats_type && form.data.ats_slug) {
            return; // user already supplied values; don't overwrite
        }

        const handle = window.setTimeout(async () => {
            detectAbort.current?.abort();
            const controller = new AbortController();
            detectAbort.current = controller;
            setDetecting(true);
            setDetectionHint(null);

            try {
                const response = await fetch('/companies/detect-ats', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken(),
                        Accept: 'application/json',
                    },
                    body: JSON.stringify({ url: websiteForDetection }),
                    signal: controller.signal,
                });

                if (!response.ok) {
                    return;
                }

                const body = (await response.json()) as {
                    detected: { ats_type: string; ats_slug: string; ats_host?: string } | null;
                };
                if (body.detected) {
                    form.setData((prev) => ({
                        ...prev,
                        ats_type: prev.ats_type || body.detected!.ats_type,
                        ats_slug: prev.ats_slug || body.detected!.ats_slug,
                        // Workday careers URLs also carry the numbered pod
                        // host; storing it saves the scraper a probing pass.
                        ats_host: prev.ats_host || (body.detected!.ats_host ?? ''),
                    }));
                    setDetectionHint(`Detected ${body.detected.ats_type} (slug: ${body.detected.ats_slug})`);
                } else {
                    setDetectionHint('No ATS detected from this URL. You can set it manually below.');
                }
            } catch (e) {
                if ((e as Error).name !== 'AbortError') {
                    setDetectionHint(null);
                }
            } finally {
                setDetecting(false);
            }
        }, 400);

        return () => window.clearTimeout(handle);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [websiteForDetection]);

    function submit(e: React.FormEvent) {
        e.preventDefault();
        if (method === 'put') {
            form.put(action);
            return;
        }
        form.post(action);
    }

    return (
        <form onSubmit={submit} className="flex flex-col gap-8">
            <section className="flex flex-col gap-4 border-t border-border pt-6 first:border-t-0 first:pt-0">
                <SectionLabel>Identity</SectionLabel>

                <Field id="name" label="Name" error={form.errors.name} required>
                    <Input id="name" name="name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} autoFocus />
                </Field>

                <Field id="website" label="Website" error={form.errors.website} hint="Used to derive the domain when none is given.">
                    <Input
                        id="website"
                        name="website"
                        type="url"
                        value={form.data.website}
                        onChange={(e) => form.setData('website', e.target.value)}
                        placeholder="https://example.com"
                    />
                </Field>

                <Field
                    id="careers_url"
                    label="Careers page"
                    error={form.errors.careers_url}
                    hint="If this URL points at a known ATS, we'll detect the slug automatically."
                >
                    <Input
                        id="careers_url"
                        name="careers_url"
                        type="url"
                        value={form.data.careers_url}
                        onChange={(e) => form.setData('careers_url', e.target.value)}
                        placeholder="https://jobs.lever.co/example"
                    />
                </Field>

                <Field id="domain" label="Domain" error={form.errors.domain} hint="Lowercase host. Leave blank to derive from website.">
                    <Input
                        id="domain"
                        name="domain"
                        value={form.data.domain}
                        onChange={(e) => form.setData('domain', e.target.value)}
                        placeholder="example.com"
                    />
                </Field>
            </section>

            <section className="flex flex-col gap-4 border-t border-border pt-6 first:border-t-0 first:pt-0">
                <div className="flex items-baseline justify-between gap-3">
                    <SectionLabel>ATS</SectionLabel>
                    {detecting ? (
                        <span className="text-xs text-muted-foreground">Detecting…</span>
                    ) : detectionHint ? (
                        <span className="text-xs text-muted-foreground">{detectionHint}</span>
                    ) : null}
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                    <Field id="ats_type" label="ATS type" error={form.errors.ats_type}>
                        <SelectInput
                            id="ats_type"
                            value={form.data.ats_type}
                            onChange={(v) => form.setData('ats_type', v)}
                            options={[{ value: '', label: 'None' }, ...options.ats_types.map((a) => ({ value: a, label: humanize(a) }))]}
                        />
                    </Field>

                    <Field id="ats_slug" label="ATS slug" error={form.errors.ats_slug}>
                        <Input
                            id="ats_slug"
                            name="ats_slug"
                            value={form.data.ats_slug}
                            onChange={(e) => form.setData('ats_slug', e.target.value)}
                            placeholder="acme"
                        />
                    </Field>
                </div>
            </section>

            <section className="flex flex-col gap-4 border-t border-border pt-6 first:border-t-0 first:pt-0">
                <SectionLabel>Classification</SectionLabel>

                <div className="grid gap-4 sm:grid-cols-2">
                    <Field id="tier" label="Tier" error={form.errors.tier}>
                        <SelectInput
                            id="tier"
                            value={form.data.tier}
                            onChange={(v) => form.setData('tier', v)}
                            options={options.tiers.map((t) => ({
                                value: t,
                                label: t === 'graveyard' ? 'Graveyard' : `Tier ${t}`,
                            }))}
                        />
                    </Field>

                    <Field id="status" label="Status" error={form.errors.status}>
                        <SelectInput
                            id="status"
                            value={form.data.status}
                            onChange={(v) => form.setData('status', v)}
                            options={options.statuses.map((s) => ({ value: s, label: humanize(s) }))}
                        />
                    </Field>
                </div>
            </section>

            <div className="flex items-center gap-3">
                <Button type="submit" disabled={form.processing}>
                    {form.processing ? 'Saving…' : mode === 'create' ? 'Add to watch list' : 'Save changes'}
                </Button>
                <Button type="button" variant="ghost" asChild>
                    <a href={cancelHref}>Cancel</a>
                </Button>
            </div>
        </form>
    );
}

interface FieldProps {
    id: string;
    label: string;
    error?: string;
    hint?: string;
    required?: boolean;
    children: React.ReactNode;
}

function Field({ id, label, error, hint, required, children }: FieldProps) {
    return (
        <div className="flex flex-col gap-1.5">
            <Label htmlFor={id}>
                {label}
                {required ? (
                    <span className="text-destructive" aria-hidden="true">
                        {' '}
                        *
                    </span>
                ) : null}
            </Label>
            {children}
            {error ? (
                <p className="text-sm text-destructive">{error}</p>
            ) : hint ? (
                <p className="text-xs text-pretty text-muted-foreground">{hint}</p>
            ) : null}
        </div>
    );
}

interface SelectInputProps {
    id: string;
    value: string;
    onChange: (value: string) => void;
    options: { value: string; label: string }[];
}

function SelectInput({ id, value, onChange, options }: SelectInputProps) {
    return (
        <NativeSelect id={id} name={id} value={value} onChange={(e) => onChange(e.target.value)}>
            {options.map((option) => (
                <option key={option.value} value={option.value}>
                    {option.label}
                </option>
            ))}
        </NativeSelect>
    );
}
