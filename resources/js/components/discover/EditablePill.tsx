import { Pencil } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

import { PARSED_SLOT_FOR_FIELD } from './helpers';
import type { EditableField, Listing } from './types';

export interface EditablePillProps {
    listing: Listing;
    field: EditableField;
    label: string;
    emphasis?: 'default' | 'muted';
    placeholder?: boolean;
    /**
     * Placeholder pills always reserve their width (so revealing them never
     * shifts the row) but only paint when the row is focused (`revealed`) or
     * hovered. Real-value pills always paint.
     */
    revealed?: boolean;
    className?: string;
    onEdit: (field: EditableField) => void;
}

export function EditablePill({
    listing,
    field,
    label,
    emphasis = 'default',
    placeholder = false,
    revealed = false,
    className,
    onEdit,
}: EditablePillProps) {
    const slot = listing.parsed?.[PARSED_SLOT_FOR_FIELD[field]] ?? null;
    const confidence = slot?.confidence;
    const muted = emphasis === 'muted';
    const isNumeric = field === 'comp_min_usd' || field === 'comp_max_usd';
    const toneClass = placeholder
        ? 'rounded-md border border-dashed border-border text-xs text-muted-foreground'
        : muted
          ? 'text-muted-foreground'
          : '';

    return (
        <button
            type="button"
            onClick={(e) => {
                e.stopPropagation();
                onEdit(field);
            }}
            className={cn(
                'group inline-flex items-center gap-1 rounded-md px-1 hover:bg-accent focus-visible:outline-2 focus-visible:outline-ring',
                toneClass,
                placeholder && cn('invisible group-hover/row:visible', revealed ? 'visible' : 'max-sm:hidden'),
                className,
            )}
            title={placeholder ? `Add ${field.replace('_', ' ')}` : `Edit ${field.replace('_', ' ')}`}
        >
            <span className={isNumeric ? 'tabular-nums' : undefined}>{label}</span>
            {!placeholder && confidence != null && confidence < 0.7 && (
                <Badge variant="warning" className="hidden group-hover:inline-flex">
                    {Math.round(confidence * 100)}%
                </Badge>
            )}
            <Pencil aria-hidden="true" className="hidden size-3 shrink-0 text-muted-foreground group-hover:inline" />
        </button>
    );
}
