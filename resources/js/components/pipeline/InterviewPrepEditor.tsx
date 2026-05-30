import { useForm } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import type { ApplicationMetadata } from '@/components/pipeline/ApplicationMetadataForm';

interface PrepFormValues {
    applied_at: string;
    application_source: string;
    referral_contact_id: string;
    next_action: string;
    next_action_date: string;
    general_notes: string;
    interview_prep: string;
}

interface InterviewPrepEditorProps {
    roleId: number;
    interviewPrep: string | null;
    application: ApplicationMetadata;
}

/** Trims an ISO timestamp down to the YYYY-MM-DD the date columns store. */
function toDateInput(value: string | null): string {
    if (!value) return '';
    return value.slice(0, 10);
}

/**
 * Markdown editor for the role's interview-prep checklist. The single update
 * endpoint replaces every column on save, so this editor carries the current
 * application metadata alongside the prep body to avoid wiping it.
 */
export default function InterviewPrepEditor({ roleId, interviewPrep, application }: InterviewPrepEditorProps) {
    const form = useForm<PrepFormValues>({
        applied_at: toDateInput(application.applied_at),
        application_source: application.application_source ?? '',
        referral_contact_id: application.referral_contact_id !== null ? String(application.referral_contact_id) : '',
        next_action: application.next_action ?? '',
        next_action_date: toDateInput(application.next_action_date),
        general_notes: application.general_notes ?? '',
        interview_prep: interviewPrep ?? '',
    });

    function submit(e: React.FormEvent) {
        e.preventDefault();
        form.put(`/pipeline/roles/${roleId}/application`, { preserveScroll: true });
    }

    return (
        <form onSubmit={submit} className="space-y-4">
            <p className="text-sm text-muted-foreground">
                Plain markdown. Seeded from the company&rsquo;s latest note when the role first entered the pipeline.
            </p>
            <textarea
                className="min-h-64 w-full rounded-md border border-input bg-background p-3 font-mono text-sm leading-relaxed shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                value={form.data.interview_prep}
                onChange={(e) => form.setData('interview_prep', e.target.value)}
                placeholder={'## Topics to review\n- System design\n- Behavioral stories\n\n## Questions to ask'}
            />
            {form.errors.interview_prep ? (
                <span className="text-sm text-destructive">{form.errors.interview_prep}</span>
            ) : null}
            <div className="flex items-center gap-3">
                <Button type="submit" disabled={form.processing}>
                    {form.processing ? 'Saving…' : 'Save prep notes'}
                </Button>
                {form.recentlySuccessful ? <span className="text-sm text-teal-700">Saved.</span> : null}
            </div>
        </form>
    );
}
