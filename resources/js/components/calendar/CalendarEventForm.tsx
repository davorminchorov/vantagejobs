import { useForm } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';

export interface CalendarEventFormValues {
    title: string;
    starts_at: string;
    timezone: string;
    type: string;
    owner_type: string;
    owner_id: string;
    url: string;
    notes: string;
    reminder_minutes: string;
}

interface CalendarEventFormProps {
    mode: 'create' | 'edit';
    initial: Partial<CalendarEventFormValues>;
    types: string[];
    ownerTypes: string[];
    action: string;
    method?: 'post' | 'put';
    onCancel?: () => void;
    onSuccess?: () => void;
}

function browserTimezone(): string {
    try {
        return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
    } catch {
        return 'UTC';
    }
}

const EMPTY: CalendarEventFormValues = {
    title: '',
    starts_at: '',
    timezone: browserTimezone(),
    type: 'interview',
    owner_type: '',
    owner_id: '',
    url: '',
    notes: '',
    reminder_minutes: '30',
};

export function humanizeType(value: string): string {
    return value
        .split('_')
        .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
        .join(' ');
}

export default function CalendarEventForm({
    mode,
    initial,
    types,
    ownerTypes,
    action,
    method = 'post',
    onCancel,
    onSuccess,
}: CalendarEventFormProps) {
    const form = useForm<CalendarEventFormValues>({ ...EMPTY, ...initial });

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
                <h2 className="font-mono text-sm font-semibold uppercase tracking-wide text-muted-foreground">Event</h2>

                <Field id="title" label="Title" error={form.errors.title} required>
                    <Input
                        id="title"
                        value={form.data.title}
                        onChange={(e) => form.setData('title', e.target.value)}
                        placeholder="Onsite with the platform team"
                        autoFocus
                    />
                </Field>

                <div className="grid gap-4 sm:grid-cols-2">
                    <Field id="starts_at" label="Starts at" error={form.errors.starts_at} required>
                        <Input
                            id="starts_at"
                            type="datetime-local"
                            value={form.data.starts_at}
                            onChange={(e) => form.setData('starts_at', e.target.value)}
                        />
                    </Field>

                    <Field id="timezone" label="Timezone" error={form.errors.timezone} required hint="IANA zone the time is entered in.">
                        <Input
                            id="timezone"
                            value={form.data.timezone}
                            onChange={(e) => form.setData('timezone', e.target.value)}
                            placeholder="America/New_York"
                        />
                    </Field>
                </div>

                <Field id="type" label="Type" error={form.errors.type} required>
                    <SelectInput
                        id="type"
                        value={form.data.type}
                        onChange={(v) => form.setData('type', v)}
                        options={types.map((t) => ({ value: t, label: humanizeType(t) }))}
                    />
                </Field>
            </section>

            <section className="space-y-4">
                <h2 className="font-mono text-sm font-semibold uppercase tracking-wide text-muted-foreground">Link</h2>

                <div className="grid gap-4 sm:grid-cols-2">
                    <Field
                        id="owner_type"
                        label="Attach to"
                        error={form.errors.owner_type}
                        hint="Optional — leave blank for a standalone reminder."
                    >
                        <SelectInput
                            id="owner_type"
                            value={form.data.owner_type}
                            onChange={(v) => {
                                form.setData('owner_type', v);
                                if (v === '') {
                                    form.setData('owner_id', '');
                                }
                            }}
                            options={[
                                { value: '', label: 'Nothing' },
                                ...ownerTypes.map((t) => ({ value: t, label: humanizeType(t) })),
                            ]}
                        />
                    </Field>

                    <Field id="owner_id" label="Owner ID" error={form.errors.owner_id} hint="ID of the linked record.">
                        <Input
                            id="owner_id"
                            type="number"
                            value={form.data.owner_id}
                            onChange={(e) => form.setData('owner_id', e.target.value)}
                            disabled={form.data.owner_type === ''}
                        />
                    </Field>
                </div>

                <Field id="url" label="URL" error={form.errors.url}>
                    <Input
                        id="url"
                        type="url"
                        value={form.data.url}
                        onChange={(e) => form.setData('url', e.target.value)}
                        placeholder="https://meet.example.com/…"
                    />
                </Field>

                <Field
                    id="reminder_minutes"
                    label="Reminder (minutes before)"
                    error={form.errors.reminder_minutes}
                    hint="Leave blank to turn the reminder off."
                >
                    <Input
                        id="reminder_minutes"
                        type="number"
                        min={0}
                        value={form.data.reminder_minutes}
                        onChange={(e) => form.setData('reminder_minutes', e.target.value)}
                    />
                </Field>
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
                        placeholder="Agenda, who you're meeting, prep links."
                    />
                </Field>
            </section>

            <div className="flex items-center gap-3">
                <Button type="submit" disabled={form.processing}>
                    {form.processing ? 'Saving…' : mode === 'create' ? 'Add event' : 'Save changes'}
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
