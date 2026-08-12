import { router } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { RelativeDate } from '@/lib/dates';
import { cn } from '@/lib/utils';

export type PipelineStage =
    | 'researching'
    | 'applied'
    | 'screen'
    | 'onsite'
    | 'offer'
    | 'rejected'
    | 'withdrawn';

export interface StageNote {
    id: number;
    stage: PipelineStage;
    body: string;
    created_at: string;
}

interface StageNotesPanelProps {
    roleId: number;
    notes: StageNote[];
    /**
     * The stage new notes are filed under. Defaults to the role's current
     * pipeline status so notes attach to whatever stage the work is in.
     */
    activeStage: PipelineStage;
    className?: string;
}

const STAGE_LABELS: Record<PipelineStage, string> = {
    researching: 'Researching',
    applied: 'Applied',
    screen: 'Screen',
    onsite: 'Onsite',
    offer: 'Offer',
    rejected: 'Rejected',
    withdrawn: 'Withdrawn',
};

const STAGE_ORDER: PipelineStage[] = [
    'researching',
    'applied',
    'screen',
    'onsite',
    'offer',
    'rejected',
    'withdrawn',
];

export default function StageNotesPanel({ roleId, notes, activeStage, className }: StageNotesPanelProps) {
    const [body, setBody] = useState('');
    const [busy, setBusy] = useState(false);

    const grouped = STAGE_ORDER.map((stage) => ({
        stage,
        notes: notes
            .filter((note) => note.stage === stage)
            .sort((a, b) => new Date(a.created_at).getTime() - new Date(b.created_at).getTime()),
    })).filter((group) => group.notes.length > 0);

    function submit() {
        const trimmed = body.trim();
        if (trimmed === '' || busy) {
            return;
        }

        setBusy(true);
        router.post(
            `/pipeline/roles/${roleId}/stage-notes`,
            { stage: activeStage, body: trimmed },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => setBody(''),
                onFinish: () => setBusy(false),
            },
        );
    }

    return (
        <section className={cn('space-y-4', className)}>
            <header className="flex items-center justify-between">
                <h2 className="text-sm font-medium text-zinc-900 dark:text-zinc-100">Stage notes</h2>
                <span className="rounded-full bg-teal-50 px-2 py-0.5 text-xs font-medium text-teal-700 dark:bg-teal-950 dark:text-teal-300">
                    {STAGE_LABELS[activeStage]}
                </span>
            </header>

            {grouped.length === 0 ? (
                <p className="text-sm text-zinc-500 dark:text-zinc-400">
                    No notes yet. Anything you log here is kept permanently, even after the role moves on.
                </p>
            ) : (
                <ol className="space-y-5">
                    {grouped.map((group) => (
                        <li key={group.stage} className="space-y-2">
                            <div className="flex items-center gap-2">
                                <span className="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                    {STAGE_LABELS[group.stage]}
                                </span>
                                <span className="h-px flex-1 bg-zinc-200 dark:bg-zinc-800" aria-hidden="true" />
                            </div>
                            <ul className="space-y-2">
                                {group.notes.map((note) => (
                                    <li
                                        key={note.id}
                                        className="rounded-lg border border-zinc-200 bg-white p-3 dark:border-zinc-800 dark:bg-zinc-900"
                                    >
                                        <p className="whitespace-pre-wrap text-sm text-zinc-800 dark:text-zinc-200">
                                            {note.body}
                                        </p>
                                        <RelativeDate
                                            iso={note.created_at}
                                            className="mt-2 block font-mono text-xs tabular-nums text-zinc-400 dark:text-zinc-500"
                                        />
                                    </li>
                                ))}
                            </ul>
                        </li>
                    ))}
                </ol>
            )}

            <div className="space-y-2 border-t border-zinc-200 pt-4 dark:border-zinc-800">
                <label htmlFor="stage-note-body" className="sr-only">
                    Add a note for the {STAGE_LABELS[activeStage]} stage
                </label>
                <textarea
                    id="stage-note-body"
                    value={body}
                    disabled={busy}
                    placeholder={`Add a note for the ${STAGE_LABELS[activeStage]} stage…`}
                    onChange={(event) => setBody(event.target.value)}
                    onKeyDown={(event) => {
                        if ((event.metaKey || event.ctrlKey) && event.key === 'Enter') {
                            event.preventDefault();
                            submit();
                        }
                    }}
                    rows={3}
                    className={cn(
                        'w-full resize-y rounded-md border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 shadow-sm',
                        'placeholder:text-zinc-400 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500',
                        'dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-100 dark:placeholder:text-zinc-500',
                    )}
                />
                <div className="flex items-center justify-end">
                    <Button type="button" onClick={submit} disabled={busy || body.trim() === ''}>
                        {busy ? 'Saving…' : 'Add note'}
                    </Button>
                </div>
            </div>
        </section>
    );
}
