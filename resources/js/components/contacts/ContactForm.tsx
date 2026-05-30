import { useForm } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';

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
        <form onSubmit={submit} className="space-y-6">
            <section className="space-y-4">
                <h2 className="font-mono text-sm font-semibold uppercase tracking-wide text-muted-foreground">Identity</h2>

                <Field id="name" label="Name" error={form.errors.name} required>
                    <Input id="name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} autoFocus />
                </Field>

                <div className="grid gap-4 sm:grid-cols-2">
                    <Field id="role_title" label="Role title" error={form.errors.role_title}>
                        <Input
                            id="role_title"
                            value={form.data.role_title}
                            onChange={(e) => form.setData('role_title', e.target.value)}
                            placeholder="Engineering Manager"
                        />
                    </Field>

                    <Field id="company_id" label="Company" error={form.errors.company_id} hint="Optional — leave blank for an unaffiliated contact.">
                        <SelectInput
                            id="company_id"
                            value={form.data.company_id}
                            onChange={(v) => form.setData('company_id', v)}
                            options={[{ value: '', label: 'No company' }, ...companies.map((c) => ({ value: String(c.id), label: c.name }))]}
                        />
                    </Field>
                </div>
            </section>

            <section className="space-y-4">
                <h2 className="font-mono text-sm font-semibold uppercase tracking-wide text-muted-foreground">Reach</h2>

                <div className="grid gap-4 sm:grid-cols-2">
                    <Field id="email" label="Email" error={form.errors.email}>
                        <Input
                            id="email"
                            type="email"
                            value={form.data.email}
                            onChange={(e) => form.setData('email', e.target.value)}
                            placeholder="name@example.com"
                        />
                    </Field>

                    <Field id="linkedin_url" label="LinkedIn" error={form.errors.linkedin_url}>
                        <Input
                            id="linkedin_url"
                            type="url"
                            value={form.data.linkedin_url}
                            onChange={(e) => form.setData('linkedin_url', e.target.value)}
                            placeholder="https://www.linkedin.com/in/…"
                        />
                    </Field>
                </div>
            </section>

            <section className="space-y-4">
                <h2 className="font-mono text-sm font-semibold uppercase tracking-wide text-muted-foreground">Notes</h2>

                <Field id="notes" label="Notes" error={form.errors.notes}>
                    <textarea
                        id="notes"
                        value={form.data.notes}
                        onChange={(e) => form.setData('notes', e.target.value)}
                        rows={4}
                        className={cn(
                            'rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm',
                            'focus-visible:ring-ring focus-visible:outline-none focus-visible:ring-2',
                        )}
                        placeholder="How you met, context, anything worth remembering."
                    />
                </Field>
            </section>

            <div className="flex items-center gap-3">
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
            {error ? <span className="text-sm text-destructive">{error}</span> : hint ? <span className="text-sm text-muted-foreground">{hint}</span> : null}
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
        <select
            id={id}
            value={value}
            onChange={(e) => onChange(e.target.value)}
            className={cn(
                'h-9 rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm',
                'focus-visible:ring-ring focus-visible:outline-none focus-visible:ring-2',
            )}
        >
            {options.map((option) => (
                <option key={option.value} value={option.value}>
                    {option.label}
                </option>
            ))}
        </select>
    );
}
