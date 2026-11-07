import { router } from '@inertiajs/react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { SectionLabel } from '@/components/ui/section-label';
import { RelativeDate } from '@/lib/dates';
import { cn } from '@/lib/utils';

export interface StageNote {
    id: number;
    stage: string;
    body: string;
    created_at: string;
}

export interface StageLabel {
    value: string;
    label: string;
}

interface StageNotesPanelProps {
    roleId: number;
    notes: StageNote[];
    /** Every stage in display order, with its label. */
    stages: StageLabel[];
    /**
     * The stage new notes are filed under. Defaults to the role's current
     * pipeline status so notes attach to whatever stage the work is in.
     */
    activeStage: string;
    className?: string;
}

export default function StageNotesPanel({ roleId, notes, stages, activeStage, className }: StageNotesPanelProps) {
    const stageLabel = (value: string): string => stages.find((stage) => stage.value === value)?.label ?? value;

    const [body, setBody] = useState('');
    const [busy, setBusy] = useState(false);

    const grouped = stages
        .map(({ value: stage }) => ({
            stage,
            notes: notes
                .filter((note) => note.stage === stage)
                .sort((a, b) => new Date(a.created_at).getTime() - new Date(b.created_at).getTime()),
        }))
        .filter((group) => group.notes.length > 0);

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
        <section className={cn('flex flex-col gap-6', className)}>
            <header className="flex items-center justify-between gap-2">
                <h2 className="text-base font-semibold text-balance">Stage notes</h2>
                <Badge variant="default">{stageLabel(activeStage)}</Badge>
            </header>

            {grouped.length === 0 ? (
                <p className="text-base text-pretty text-muted-foreground sm:text-sm">
                    No notes yet. Anything you log here is kept permanently, even after the role moves on.
                </p>
            ) : (
                <ol role="list" className="flex flex-col gap-6">
                    {grouped.map((group) => (
                        <li key={group.stage} className="flex flex-col gap-2">
                            <SectionLabel>{stageLabel(group.stage)}</SectionLabel>
                            <ul role="list" className="divide-y divide-border border-y border-border">
                                {group.notes.map((note) => (
                                    <li key={note.id} className="flex flex-col gap-1 py-3">
                                        <p className="text-base whitespace-pre-wrap text-foreground sm:text-sm">{note.body}</p>
                                        <RelativeDate iso={note.created_at} className="block text-xs tabular-nums text-muted-foreground" />
                                    </li>
                                ))}
                            </ul>
                        </li>
                    ))}
                </ol>
            )}

            <div className="flex flex-col gap-2 border-t border-border pt-4">
                <label htmlFor="stage-note-body" className="sr-only">
                    Add a note for the {stageLabel(activeStage)} stage
                </label>
                <textarea
                    id="stage-note-body"
                    name="stage_note_body"
                    value={body}
                    disabled={busy}
                    placeholder={`Add a note for the ${stageLabel(activeStage)} stage…`}
                    onChange={(event) => setBody(event.target.value)}
                    onKeyDown={(event) => {
                        if ((event.metaKey || event.ctrlKey) && event.key === 'Enter') {
                            event.preventDefault();
                            submit();
                        }
                    }}
                    rows={3}
                    className={cn(
                        'resize-y',
                        'w-full rounded-md border border-input bg-background px-3 py-2 text-base placeholder:text-muted-foreground focus-visible:outline-2 focus-visible:-outline-offset-1 focus-visible:outline-ring sm:text-sm',
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
