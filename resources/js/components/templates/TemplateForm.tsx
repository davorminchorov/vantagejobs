import { useForm } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';

export interface TemplateFormValues {
    name: string;
    category: string;
    body: string;
}

interface TemplateFormProps {
    mode: 'create' | 'edit';
    initial: Partial<TemplateFormValues>;
    categories: string[];
    action: string;
    method?: 'post' | 'put';
    onCancel: () => void;
    onSuccess?: () => void;
}

const EMPTY: TemplateFormValues = {
    name: '',
    category: 'cold_intro',
    body: '',
};

export function humanizeCategory(value: string): string {
    return value.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

export default function TemplateForm({ mode, initial, categories, action, method = 'post', onCancel, onSuccess }: TemplateFormProps) {
    const form = useForm<TemplateFormValues>({ ...EMPTY, ...initial });

    function submit(e: React.FormEvent) {
        e.preventDefault();

        const options = {
            preserveScroll: true,
            onSuccess: () => {
                if (mode === 'create') {
                    form.reset();
                }
                onSuccess?.();
            },
        };

        if (method === 'put') {
            form.put(action, options);
            return;
        }
        form.post(action, options);
    }

    return (
        <form onSubmit={submit} className="space-y-5">
            <div className="grid gap-4 sm:grid-cols-2">
                <Field id="name" label="Name" error={form.errors.name} required>
                    <Input
                        id="name"
                        value={form.data.name}
                        onChange={(e) => form.setData('name', e.target.value)}
                        placeholder="Cold intro to hiring manager"
                        autoFocus
                    />
                </Field>

                <Field id="category" label="Category" error={form.errors.category} required>
                    <select
                        id="category"
                        value={form.data.category}
                        onChange={(e) => form.setData('category', e.target.value)}
                        className={cn(
                            'h-9 rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm',
                            'focus-visible:ring-ring focus-visible:outline-none focus-visible:ring-2',
                        )}
                    >
                        {categories.map((category) => (
                            <option key={category} value={category}>
                                {humanizeCategory(category)}
                            </option>
                        ))}
                    </select>
                </Field>
            </div>

            <Field
                id="body"
                label="Body"
                error={form.errors.body}
                hint="Markdown. Use {{contact_first_name}}, {{contact_last_name}}, {{company}}, {{role}} as placeholders."
                required
            >
                <textarea
                    id="body"
                    value={form.data.body}
                    onChange={(e) => form.setData('body', e.target.value)}
                    rows={10}
                    placeholder={'Hi {{contact_first_name}},\n\n…'}
                    className={cn(
                        'min-h-40 w-full rounded-md border border-input bg-background px-3 py-2 font-mono text-sm shadow-sm',
                        'focus-visible:ring-ring focus-visible:outline-none focus-visible:ring-2',
                    )}
                />
            </Field>

            <div className="flex items-center gap-3">
                <Button type="submit" disabled={form.processing}>
                    {form.processing ? 'Saving…' : mode === 'create' ? 'Create template' : 'Save changes'}
                </Button>
                <Button type="button" variant="ghost" onClick={onCancel}>
                    Cancel
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
                {required ? <span className="text-destructive"> *</span> : null}
            </Label>
            {children}
            {error ? (
                <span className="text-sm text-destructive">{error}</span>
            ) : hint ? (
                <span className="text-sm text-muted-foreground">{hint}</span>
            ) : null}
        </div>
    );
}
