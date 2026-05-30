import { useForm } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';

export interface ApplicationMetadata {
    applied_at: string | null;
    application_source: string | null;
    referral_contact_id: number | null;
    next_action: string | null;
    next_action_date: string | null;
    general_notes: string | null;
}

export interface ContactOption {
    id: number;
    name: string;
    role_title: string | null;
}

interface ApplicationFormValues {
    applied_at: string;
    application_source: string;
    referral_contact_id: string;
    next_action: string;
    next_action_date: string;
    general_notes: string;
    interview_prep: string;
}

interface ApplicationMetadataFormProps {
    roleId: number;
    application: ApplicationMetadata;
    interviewPrep: string | null;
    contacts: ContactOption[];
    sources: string[];
}

function humanize(value: string): string {
    return value.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

/** Trims an ISO timestamp down to the YYYY-MM-DD a date input expects. */
function toDateInput(value: string | null): string {
    if (!value) return '';
    return value.slice(0, 10);
}

export default function ApplicationMetadataForm({
    roleId,
    application,
    interviewPrep,
    contacts,
    sources,
}: ApplicationMetadataFormProps) {
    const form = useForm<ApplicationFormValues>({
        applied_at: toDateInput(application.applied_at),
        application_source: application.application_source ?? '',
        referral_contact_id: application.referral_contact_id !== null ? String(application.referral_contact_id) : '',
        next_action: application.next_action ?? '',
        next_action_date: toDateInput(application.next_action_date),
        general_notes: application.general_notes ?? '',
        // interview_prep is owned by the prep editor; we carry the last-known
        // value so this form's PUT never clobbers it.
        interview_prep: interviewPrep ?? '',
    });

    function submit(e: React.FormEvent) {
        e.preventDefault();
        form.put(`/pipeline/roles/${roleId}/application`, { preserveScroll: true });
    }

    return (
        <form onSubmit={submit} className="space-y-5">
            <div className="grid gap-4 sm:grid-cols-2">
                <Field id="applied_at" label="Applied on" error={form.errors.applied_at}>
                    <Input
                        id="applied_at"
                        type="date"
                        value={form.data.applied_at}
                        onChange={(e) => form.setData('applied_at', e.target.value)}
                    />
                </Field>

                <Field id="application_source" label="Source" error={form.errors.application_source}>
                    <SelectInput
                        id="application_source"
                        value={form.data.application_source}
                        onChange={(v) => form.setData('application_source', v)}
                        options={[
                            { value: '', label: 'Unspecified' },
                            ...sources.map((s) => ({ value: s, label: humanize(s) })),
                        ]}
                    />
                </Field>

                <Field id="referral_contact_id" label="Referral contact" error={form.errors.referral_contact_id}>
                    <SelectInput
                        id="referral_contact_id"
                        value={form.data.referral_contact_id}
                        onChange={(v) => form.setData('referral_contact_id', v)}
                        options={[
                            { value: '', label: 'None' },
                            ...contacts.map((c) => ({
                                value: String(c.id),
                                label: c.role_title ? `${c.name} · ${c.role_title}` : c.name,
                            })),
                        ]}
                    />
                </Field>

                <Field id="next_action_date" label="Next action date" error={form.errors.next_action_date}>
                    <Input
                        id="next_action_date"
                        type="date"
                        value={form.data.next_action_date}
                        onChange={(e) => form.setData('next_action_date', e.target.value)}
                    />
                </Field>
            </div>

            <Field id="next_action" label="Next action" error={form.errors.next_action}>
                <Input
                    id="next_action"
                    value={form.data.next_action}
                    onChange={(e) => form.setData('next_action', e.target.value)}
                    placeholder="e.g. Follow up with recruiter"
                />
            </Field>

            <Field id="general_notes" label="General notes" error={form.errors.general_notes}>
                <textarea
                    id="general_notes"
                    className="min-h-28 w-full rounded-md border border-input bg-background p-2 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    value={form.data.general_notes}
                    onChange={(e) => form.setData('general_notes', e.target.value)}
                    placeholder="Free-form notes about this application."
                />
            </Field>

            <div className="flex items-center gap-3">
                <Button type="submit" disabled={form.processing}>
                    {form.processing ? 'Saving…' : 'Save application details'}
                </Button>
                {form.recentlySuccessful ? (
                    <span className="text-sm text-teal-700">Saved.</span>
                ) : null}
            </div>
        </form>
    );
}

interface FieldProps {
    id: string;
    label: string;
    error?: string;
    children: React.ReactNode;
}

function Field({ id, label, error, children }: FieldProps) {
    return (
        <div className="flex flex-col gap-1.5">
            <Label htmlFor={id}>{label}</Label>
            {children}
            {error ? <span className="text-sm text-destructive">{error}</span> : null}
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
