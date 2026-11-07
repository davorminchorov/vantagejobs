import { useState } from 'react';
import type { FormEvent } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';

import { PARSED_SLOT_FOR_FIELD, currentValueFor } from './helpers';
import type { CorrectionValue, EditableField, Listing } from './types';

export interface CorrectionEditorProps {
    listing: Listing;
    field: EditableField;
    debug: boolean;
    onSave: (value: CorrectionValue) => void;
    onCancel: () => void;
    onSaveFixture: () => void;
}

export function CorrectionEditor({ listing, field, debug, onSave, onCancel, onSaveFixture }: CorrectionEditorProps) {
    const slot = listing.parsed?.[PARSED_SLOT_FOR_FIELD[field]] ?? null;
    const currentValue = currentValueFor(listing, field);
    const [value, setValue] = useState<CorrectionValue>(currentValue);
    const [showRaw, setShowRaw] = useState(false);

    function submit(e: FormEvent<HTMLFormElement>) {
        e.preventDefault();
        onSave(value);
    }

    return (
        <form
            onSubmit={submit}
            onClick={(e) => e.stopPropagation()}
            className="mt-2 flex flex-col gap-3 rounded-xl border border-border bg-background p-4 text-base sm:text-sm"
        >
            <div className="flex flex-wrap items-center gap-2">
                <span className="font-medium">Edit {field.replace('_', ' ')}</span>
                {slot && (
                    <>
                        <Badge variant={slot.confidence >= 0.7 ? 'success' : 'warning'}>
                            confidence <span className="tabular-nums">{(slot.confidence * 100).toFixed(0)}%</span>
                        </Badge>
                        <Badge variant="outline">source: {slot.source}</Badge>
                    </>
                )}
            </div>

            {slot?.evidence && (
                <p className="rounded-md bg-muted px-2 py-1 text-xs text-pretty text-muted-foreground">
                    <span className="font-medium text-foreground">evidence:</span> {slot.evidence}
                </p>
            )}

            <FieldInput field={field} value={value} onChange={setValue} />

            <div className="flex flex-wrap items-center gap-2">
                <Button type="submit" size="sm">
                    Save
                </Button>
                <Button type="button" size="sm" variant="ghost" onClick={onCancel}>
                    Cancel
                </Button>
                {debug && (
                    <Button type="button" size="sm" variant="outline" onClick={onSaveFixture}>
                        Save as fixture
                    </Button>
                )}
                <button
                    type="button"
                    onClick={() => setShowRaw((v) => !v)}
                    className="ml-auto text-xs text-muted-foreground hover:text-foreground focus-visible:outline-2 focus-visible:outline-ring"
                >
                    {showRaw ? 'Hide raw' : 'View raw'}
                </button>
            </div>

            {showRaw && (
                <pre className="max-h-64 overflow-auto rounded-md border border-border bg-muted p-2 font-mono text-xs leading-tight">
                    {JSON.stringify(listing.raw, null, 2)}
                </pre>
            )}
        </form>
    );
}

function FieldInput({
    field,
    value,
    onChange,
}: {
    field: EditableField;
    value: CorrectionValue;
    onChange: (v: CorrectionValue) => void;
}) {
    if (field === 'remote_type') {
        return (
            <NativeSelect
                name="remote_type"
                aria-label="Remote type"
                value={typeof value === 'string' ? value : 'unknown'}
                onChange={(e) => onChange(e.currentTarget.value)}
                wrapperClassName="w-full"
            >
                <option value="remote">remote</option>
                <option value="hybrid">hybrid</option>
                <option value="on_site">on_site</option>
                <option value="unknown">unknown</option>
            </NativeSelect>
        );
    }
    if (field === 'comp_min_usd' || field === 'comp_max_usd') {
        return (
            <Input
                type="number"
                name={field}
                aria-label={field === 'comp_min_usd' ? 'Minimum compensation (USD)' : 'Maximum compensation (USD)'}
                min={0}
                value={typeof value === 'number' ? value : ''}
                onChange={(e) => onChange(e.currentTarget.value === '' ? null : Number(e.currentTarget.value))}
                placeholder="USD per year"
            />
        );
    }
    if (field === 'stack_tags') {
        const text = Array.isArray(value) ? value.join(', ') : '';
        return (
            <Input
                type="text"
                name="stack_tags"
                aria-label="Stack tags"
                value={text}
                onChange={(e) =>
                    onChange(
                        e.currentTarget.value
                            .split(',')
                            .map((s) => s.trim())
                            .filter((s) => s !== ''),
                    )
                }
                placeholder="typescript, react, postgres"
            />
        );
    }
    return (
        <Input
            type="text"
            name={field}
            aria-label={`Edit ${field.replace('_', ' ')}`}
            value={typeof value === 'string' ? value : ''}
            onChange={(e) => onChange(e.currentTarget.value)}
        />
    );
}
