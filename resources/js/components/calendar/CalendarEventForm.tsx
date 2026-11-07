import { useForm } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { SectionLabel } from '@/components/ui/section-label';
import { NativeSelect } from '@/components/ui/native-select';
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

export interface OwnerOption {
    value: string;
    label: string;
}

interface CalendarEventFormProps {
    mode: 'create' | 'edit';
    initial: Partial<CalendarEventFormValues>;
    types: string[];
    /** Applications (and any existing owner) the event can be attached to. Values look like "role:12". */
    ownerOptions: OwnerOption[];
    /** The owner is fixed (the form was opened from an application): shown as text, not editable. */
    ownerLocked?: boolean;
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
    ownerOptions,
    ownerLocked = false,
    action,
    method = 'post',
    onCancel,
    onSuccess,
}: CalendarEventFormProps) {
    const form = useForm<CalendarEventFormValues>({ ...EMPTY, ...initial });

    const ownerValue = form.data.owner_type !== '' ? `${form.data.owner_type}:${form.data.owner_id}` : '';

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
                <SectionLabel>Event</SectionLabel>

                <Field id="title" label="Title" error={form.errors.title} required>
                    <Input
                        id="title"
                        name="title"
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
                            name="starts_at"
                            type="datetime-local"
                            value={form.data.starts_at}
                            onChange={(e) => form.setData('starts_at', e.target.value)}
                        />
                    </Field>

                    <Field id="timezone" label="Timezone" error={form.errors.timezone} required hint="IANA zone the time is entered in.">
                        <Input
                            id="timezone"
                            name="timezone"
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

            <section className="flex flex-col gap-4">
                <SectionLabel>Link</SectionLabel>

                {ownerLocked ? (
                    <div className="flex flex-col gap-1.5">
                        <span className="text-base font-medium sm:text-sm">Attached to</span>
                        <span className="text-base sm:text-sm">{ownerOptions.find((o) => o.value === ownerValue)?.label}</span>
                    </div>
                ) : (
                    <Field
                        id="owner"
                        label="Attach to"
                        error={form.errors.owner_type ?? form.errors.owner_id}
                        hint="Optional. Leave on Nothing for a standalone reminder."
                    >
                        <SelectInput
                            id="owner"
                            value={ownerValue}
                            onChange={(v) => {
                                const separator = v.indexOf(':');
                                form.setData((data) => ({
                                    ...data,
                                    owner_type: separator === -1 ? '' : v.slice(0, separator),
                                    owner_id: separator === -1 ? '' : v.slice(separator + 1),
                                }));
                            }}
                            options={[{ value: '', label: 'Nothing' }, ...ownerOptions]}
                        />
                    </Field>
                )}

                <Field id="url" label="URL" error={form.errors.url}>
                    <Input
                        id="url"
                        name="url"
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
                        name="reminder_minutes"
                        type="number"
                        min={0}
                        value={form.data.reminder_minutes}
                        onChange={(e) => form.setData('reminder_minutes', e.target.value)}
                    />
                </Field>
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
                        className={cn(
                            'w-full rounded-md border border-input bg-background px-3 py-2 text-base placeholder:text-muted-foreground',
                            'focus-visible:outline-2 focus-visible:-outline-offset-1 focus-visible:outline-ring sm:text-sm',
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
            {error ? (
                <span className="text-sm text-destructive">{error}</span>
            ) : hint ? (
                <span className="text-sm text-muted-foreground">{hint}</span>
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
        <NativeSelect id={id} name={id} value={value} onChange={(e) => onChange(e.target.value)} wrapperClassName="w-full">
            {options.map((option) => (
                <option key={option.value} value={option.value}>
                    {option.label}
                </option>
            ))}
        </NativeSelect>
    );
}
