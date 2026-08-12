import type { FormDataConvertible } from '@inertiajs/core';
import { router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { cn } from '@/lib/utils';

export type BulkEntity = 'company' | 'role' | 'aggregator_listing';

export type BulkActionKey = 'change_tier' | 'tag' | 'untag' | 'dismiss' | 'bookmark' | 'mark_closed' | 'keep' | 'snooze';

interface BulkActionOption {
    readonly key: BulkActionKey;
    readonly label: string;
    /** Requires a tier param. */
    readonly tier?: boolean;
    /** Requires a tag param. */
    readonly tag?: boolean;
    /** Takes an optional days param (defaults to SnoozeListingAction::DEFAULT_DAYS server-side). */
    readonly days?: boolean;
    /** Confirmation copy shown in the dialog. */
    readonly confirm: string;
}

const ACTIONS_BY_ENTITY: Record<BulkEntity, ReadonlyArray<BulkActionOption>> = {
    company: [
        { key: 'change_tier', label: 'Change tier', tier: true, confirm: 'Change the tier of the selected companies?' },
        { key: 'tag', label: 'Add tag', tag: true, confirm: 'Add a tag to the selected companies?' },
        { key: 'untag', label: 'Remove tag', tag: true, confirm: 'Remove a tag from the selected companies?' },
        { key: 'dismiss', label: 'Move to graveyard', confirm: 'Move the selected companies to the graveyard?' },
    ],
    role: [
        { key: 'tag', label: 'Add tag', tag: true, confirm: 'Add a tag to the selected roles?' },
        { key: 'untag', label: 'Remove tag', tag: true, confirm: 'Remove a tag from the selected roles?' },
        { key: 'bookmark', label: 'Bookmark', confirm: 'Bookmark the selected roles?' },
        { key: 'mark_closed', label: 'Mark closed', confirm: 'Mark the selected roles as closed?' },
    ],
    aggregator_listing: [
        { key: 'keep', label: 'Keep', confirm: 'Keep the selected listings?' },
        { key: 'dismiss', label: 'Dismiss', confirm: 'Dismiss the selected listings?' },
        { key: 'snooze', label: 'Snooze', days: true, confirm: 'Snooze the selected listings?' },
        { key: 'bookmark', label: 'Bookmark', confirm: 'Bookmark the selected listings?' },
    ],
};

const TIER_OPTIONS = ['A', 'B', 'C', 'graveyard'] as const;

const DEFAULT_SNOOZE_DAYS = 7;

interface UndoPayload {
    audit_log_id: number;
    count: number;
}

interface FlashWithUndo {
    undo?: UndoPayload | null;
}

interface Props {
    entity: BulkEntity;
    selectedIds: number[];
    onClear: () => void;
    /** Inertia `only` keys to refresh after the action lands. */
    only?: string[];
    /**
     * Called once the action succeeds, before selection is cleared. Lets a
     * page rendering a merged/infinite-scroll list remove the affected rows
     * client-side instead of relying on a fresh `only` fetch of that prop.
     */
    onApplied?: () => void;
}

const UNDO_WINDOW_MS = 60_000;

export default function BulkActionBar({ entity, selectedIds, onClear, only, onApplied }: Props) {
    const page = usePage<{ flash: FlashWithUndo }>();
    const [pending, setPending] = useState<BulkActionOption | null>(null);
    const [tier, setTier] = useState<string>('B');
    const [tag, setTag] = useState<string>('');
    const [days, setDays] = useState<number>(DEFAULT_SNOOZE_DAYS);
    const [submitting, setSubmitting] = useState(false);
    const [undo, setUndo] = useState<UndoPayload | null>(null);
    const undoTimer = useRef<number | null>(null);
    const seenUndoId = useRef<number | null>(null);

    const count = selectedIds.length;
    const actions = ACTIONS_BY_ENTITY[entity];

    // Surface the undo toast whenever the server flashes a fresh undo payload.
    useEffect(() => {
        const payload = page.props.flash?.undo ?? null;
        if (!payload || payload.audit_log_id === seenUndoId.current) {
            return;
        }
        seenUndoId.current = payload.audit_log_id;
        setUndo(payload);
        if (undoTimer.current) {
            window.clearTimeout(undoTimer.current);
        }
        undoTimer.current = window.setTimeout(() => setUndo(null), UNDO_WINDOW_MS);
        return () => {
            if (undoTimer.current) {
                window.clearTimeout(undoTimer.current);
            }
        };
    }, [page.props.flash?.undo]);

    function submit() {
        if (!pending || count === 0) {
            return;
        }
        const data: Record<string, FormDataConvertible> = {
            entity,
            action: pending.key,
            ids: selectedIds,
        };
        if (pending.tier) {
            data.tier = tier;
        }
        if (pending.tag) {
            data.tag = tag.trim();
        }
        if (pending.days) {
            data.days = days;
        }

        setSubmitting(true);
        router.post('/bulk-actions', data, {
            preserveScroll: true,
            preserveState: true,
            only,
            onSuccess: () => {
                setPending(null);
                setTag('');
                onApplied?.();
                onClear();
            },
            onFinish: () => setSubmitting(false),
        });
    }

    function performUndo() {
        if (!undo) {
            return;
        }
        const id = undo.audit_log_id;
        setUndo(null);
        if (undoTimer.current) {
            window.clearTimeout(undoTimer.current);
        }
        router.post(`/bulk-actions/${id}/undo`, {}, {
            preserveScroll: true,
            preserveState: true,
            only,
        });
    }

    if (count === 0 && !undo) {
        return null;
    }

    return (
        <>
            {count > 0 ? (
                <div
                    className="sticky bottom-4 z-20 mx-auto flex w-full max-w-3xl flex-wrap items-center gap-3 rounded-2xl border border-border bg-card/95 px-4 py-3 shadow-lg backdrop-blur"
                    role="region"
                    aria-label="Bulk actions"
                >
                    <span className="font-mono text-sm tabular-nums text-foreground">
                        {count} selected
                    </span>
                    <div className="flex flex-wrap items-center gap-1.5">
                        {actions.map((action) => (
                            <Button
                                key={action.key}
                                size="sm"
                                variant="outline"
                                onClick={() => {
                                    setPending(action);
                                    if (action.tag) {
                                        setTag('');
                                    }
                                    if (action.days) {
                                        setDays(DEFAULT_SNOOZE_DAYS);
                                    }
                                }}
                            >
                                {action.label}
                            </Button>
                        ))}
                    </div>
                    <Button size="sm" variant="ghost" className="ml-auto" onClick={onClear}>
                        Clear selection
                    </Button>
                </div>
            ) : null}

            <Dialog open={pending !== null} onOpenChange={(open) => (open ? null : setPending(null))}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{pending?.label}</DialogTitle>
                        <DialogDescription>
                            {pending?.confirm} This affects{' '}
                            <span className="font-mono tabular-nums">{count}</span>{' '}
                            {count === 1 ? 'item' : 'items'}.
                        </DialogDescription>
                    </DialogHeader>

                    {pending?.tier ? (
                        <div className="space-y-1.5">
                            <label className="font-mono text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                Tier
                            </label>
                            <select
                                value={tier}
                                onChange={(e) => setTier(e.target.value)}
                                className={cn(
                                    'h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm',
                                    'focus-visible:ring-ring focus-visible:outline-none focus-visible:ring-2',
                                )}
                            >
                                {TIER_OPTIONS.map((t) => (
                                    <option key={t} value={t}>
                                        {t === 'graveyard' ? 'Graveyard' : `Tier ${t}`}
                                    </option>
                                ))}
                            </select>
                        </div>
                    ) : null}

                    {pending?.tag ? (
                        <div className="space-y-1.5">
                            <label className="font-mono text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                Tag
                            </label>
                            <input
                                type="text"
                                value={tag}
                                onChange={(e) => setTag(e.target.value)}
                                placeholder="e.g. high-priority"
                                className={cn(
                                    'h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm',
                                    'focus-visible:ring-ring focus-visible:outline-none focus-visible:ring-2',
                                )}
                            />
                        </div>
                    ) : null}

                    {pending?.days ? (
                        <div className="space-y-1.5">
                            <label className="font-mono text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                Days
                            </label>
                            <input
                                type="number"
                                min={1}
                                max={90}
                                value={days}
                                onChange={(e) => setDays(Number(e.target.value))}
                                className={cn(
                                    'h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm',
                                    'focus-visible:ring-ring focus-visible:outline-none focus-visible:ring-2',
                                )}
                            />
                        </div>
                    ) : null}

                    <DialogFooter>
                        <Button variant="ghost" onClick={() => setPending(null)} disabled={submitting}>
                            Cancel
                        </Button>
                        <Button
                            onClick={submit}
                            disabled={
                                submitting ||
                                (pending?.tag === true && tag.trim() === '') ||
                                (pending?.days === true && (days < 1 || days > 90))
                            }
                        >
                            {submitting ? 'Applying…' : 'Apply'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {undo ? (
                <div
                    className="fixed bottom-6 right-6 z-30 flex items-center gap-3 rounded-xl border border-border bg-card px-4 py-3 text-sm shadow-lg"
                    role="status"
                    aria-live="polite"
                >
                    <span className="text-foreground">
                        Applied to <span className="font-mono tabular-nums">{undo.count}</span>{' '}
                        {undo.count === 1 ? 'item' : 'items'}.
                    </span>
                    <Button size="sm" variant="outline" onClick={performUndo}>
                        Undo
                    </Button>
                </div>
            ) : null}
        </>
    );
}
