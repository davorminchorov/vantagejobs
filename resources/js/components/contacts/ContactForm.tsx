import { useForm } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { SectionLabel } from '@/components/ui/section-label';
import { NativeSelect } from '@/components/ui/native-select';

export interface ContactFormValues {
    name: string;
    role_title: string;
    linkedin_url: string;
    email: string;
    notes: string;
    company_id: string;
}

export interface ContactCompanyOption {
    id: number;
    name: string;
}

interface ContactFormProps {
    mode: 'create' | 'edit';
    initial: Partial<ContactFormValues>;
    companies: ContactCompanyOption[];
    action: string;
    method?: 'post' | 'put';
    onCancel?: () => void;
    onSuccess?: () => void;
}

const EMPTY: ContactFormValues = {
    name: '',
    role_title: '',
    linkedin_url: '',
    email: '',
    notes: '',
    company_id: '',
};

export default function ContactForm({ mode, initial, companies, action, method = 'post', onCancel, onSuccess }: ContactFormProps) {
    const form = useForm<ContactFormValues>({ ...EMPTY, ...initial });

    function submit(e: React.FormEvent) {
        e.preventDefault();

        const options = {
            preserveScroll: true,
            onSuccess: () => onSuccess?.(),
        };

        if (method === 'put') {
            form.put(action, options);
            return;
        }
        form.post(action, options);
    }

    return (
        <form onSubmit={submit} className="flex flex-col gap-6">
            <section className="flex flex-col gap-4">
                <SectionLabel>Identity</SectionLabel>

                <Field id="name" label="Name" error={form.errors.name} required>
                    <Input id="name" name="name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} autoFocus />
                </Field>

                <div className="grid gap-4 sm:grid-cols-2">
                    <Field id="role_title" label="Role title" error={form.errors.role_title}>
                        <Input
                            id="role_title"
                            name="role_title"
                            value={form.data.role_title}
                            onChange={(e) => form.setData('role_title', e.target.value)}
                            placeholder="Engineering Manager"
                        />
                    </Field>

                    <Field id="company_id" label="Company" error={form.errors.company_id} hint="Optional. Leave blank for an unaffiliated contact.">
                        <SelectInput
                            id="company_id"
                            value={form.data.company_id}
                            onChange={(v) => form.setData('company_id', v)}
                            options={[{ value: '', label: 'No company' }, ...companies.map((c) => ({ value: String(c.id), label: c.name }))]}
                        />
                    </Field>
                </div>
            </section>

            <section className="flex flex-col gap-4">
                <SectionLabel>Reach</SectionLabel>

                <div className="grid gap-4 sm:grid-cols-2">
                    <Field id="email" label="Email" error={form.errors.email}>
                        <Input
                            id="email"
                            name="email"
                            type="email"
                            value={form.data.email}
                            onChange={(e) => form.setData('email', e.target.value)}
                            placeholder="name@example.com"
                        />
                    </Field>

                    <Field id="linkedin_url" label="LinkedIn" error={form.errors.linkedin_url}>
                        <Input
                            id="linkedin_url"
                            name="linkedin_url"
                            type="url"
                            value={form.data.linkedin_url}
                            onChange={(e) => form.setData('linkedin_url', e.target.value)}
                            placeholder="https://www.linkedin.com/in/…"
                        />
                    </Field>
                </div>
            </section>

            <section className="flex flex-col gap-4">
                <SectionLabel>Notes</SectionLabel>

                <Field id="notes" label="Notes" error={form.errors.notes}>
                    <textarea
                        id="notes"
                        name="notes"
                        value={form.data.notes}
                        onChange={(e) => form.setData('notes', e.target.value)}
                        rows={4}
                        className="w-full rounded-md border border-input bg-background px-3 py-2 text-base placeholder:text-muted-foreground focus-visible:outline-2 focus-visible:-outline-offset-1 focus-visible:outline-ring sm:text-sm"
                        placeholder="How you met, context, anything worth remembering."
                    />
                </Field>
            </section>

            <div className="flex flex-wrap items-center gap-3">
                <Button type="submit" disabled={form.processing}>
                    {form.processing ? 'Saving…' : mode === 'create' ? 'Add contact' : 'Save changes'}
                </Button>
                {onCancel ? (
                    <Button type="button" variant="ghost" onClick={onCancel}>
                        Cancel
                    </Button>
                ) : null}
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
                {required ? <span className="text-destructive"> *</span> : null}
            </Label>
            {children}
            {error ? <span className="text-base text-destructive sm:text-sm">{error}</span> : hint ? <span className="text-xs text-muted-foreground">{hint}</span> : null}
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
        <NativeSelect
            id={id}
            name={id}
            value={value}
            onChange={(e) => onChange(e.target.value)}
        >
            {options.map((option) => (
                <option key={option.value} value={option.value}>
                    {option.label}
                </option>
            ))}
        </NativeSelect>
    );
}
