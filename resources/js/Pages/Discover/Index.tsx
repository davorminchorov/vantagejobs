import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import type { FormEvent } from 'react';

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import BulkActionBar from '@/components/bulk/BulkActionBar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';

interface SourceRef {
    id: number;
    type: string;
    name: string;
}

interface ParsedSlot {
    value: unknown;
    confidence: number;
    source: string;
    evidence: string | null;
}

interface Listing {
    id: number;
    external_id: string;
    url: string | null;
    parsed_company_name: string | null;
    parsed_role_title: string | null;
    parsed_seniority: string | null;
    parsed_remote_type: string | null;
    parsed_comp_min_usd: number | null;
    parsed_comp_max_usd: number | null;
    has_comp: boolean;
    stack_tags: string[] | null;
    location_bucket: string | null;
    status: string;
    posted_at: string | null;
    source: SourceRef | null;
    parsed: Record<string, ParsedSlot> | null;
    parser_version: number | null;
    raw: Record<string, unknown> | null;
}

interface Paginator<T> {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    next_page_url: string | null;
    prev_page_url: string | null;
}

interface Filters {
    status: string;
    source: string | null;
    has_comp: boolean;
    remote_only: boolean;
    q: string | null;
}

interface Props {
    listings: Paginator<Listing>;
    filters: Filters;
    options: {
        sources: { value: string; label: string }[];
        statuses: string[];
    };
}

type EditableField =
    | 'company_name'
    | 'role_title'
    | 'seniority'
    | 'remote_type'
    | 'region'
    | 'comp_min_usd'
    | 'comp_max_usd'
    | 'stack_tags';

const STATUS_TABS = ['new', 'kept', 'dismissed', 'snoozed', 'linked', 'auto_dismissed', 'overflow', 'duplicate'] as const;

const PARSED_SLOT_FOR_FIELD: Record<EditableField, string> = {
    company_name: 'company_name',
    role_title: 'role_title',
    seniority: 'seniority',
    remote_type: 'remote',
    region: 'region',
    comp_min_usd: 'compensation',
    comp_max_usd: 'compensation',
    stack_tags: 'stack_tags',
};

export default function DiscoverIndex({ listings, filters, options }: Props) {
    const [focusIndex, setFocusIndex] = useState<number>(-1);
    const [editing, setEditing] = useState<{ listingId: number; field: EditableField } | null>(null);
    const [selectedIds, setSelectedIds] = useState<number[]>([]);
    const cardRefs = useRef<Array<HTMLDivElement | null>>([]);

    // Drop any selection that points at listings no longer on the page.
    useEffect(() => {
        const pageIds = new Set(listings.data.map((l) => l.id));
        setSelectedIds((prev) => prev.filter((id) => pageIds.has(id)));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [listings.data.map((l) => l.id).join(',')]);

    function toggleSelected(id: number) {
        setSelectedIds((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]));
    }

    useEffect(() => {
        function onKey(e: KeyboardEvent) {
            // Suppress global handler when typing in an input / textarea /
            // contenteditable — the correction editor lives inside cards.
            const target = e.target as HTMLElement | null;
            if (target && (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA' || target.isContentEditable)) {
                return;
            }

            const len = listings.data.length;
            if (len === 0) return;

            if (e.key === 'j' || e.key === 'ArrowDown') {
                e.preventDefault();
                setFocusIndex((i) => Math.min(len - 1, i + 1));
                return;
            }
            if (e.key === 'ArrowUp') {
                e.preventDefault();
                setFocusIndex((i) => Math.max(0, i - 1));
                return;
            }
            if (focusIndex < 0) return;

            const listing = listings.data[focusIndex];
            if (!listing) return;

            if (e.key === 'k' || e.key === 'Enter') {
                e.preventDefault();
                actOn(listing, 'keep');
            } else if (e.key === 'x') {
                e.preventDefault();
                actOn(listing, 'dismiss');
            } else if (e.key === 's') {
                e.preventDefault();
                actOn(listing, 'snooze');
            } else if (e.key === 'b') {
                e.preventDefault();
                actOn(listing, 'bookmark');
            } else if (e.key === 'Escape') {
                if (editing) {
                    setEditing(null);
                } else {
                    setFocusIndex(-1);
                }
            }
        }

        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [focusIndex, listings.data, editing]);

    useEffect(() => {
        if (focusIndex >= 0) {
            cardRefs.current[focusIndex]?.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        }
    }, [focusIndex]);

    function actOn(listing: Listing, verb: 'keep' | 'dismiss' | 'snooze' | 'bookmark') {
        router.post(
            `/discover/${listing.id}/${verb}`,
            {},
            {
                preserveScroll: true,
                preserveState: true,
                only: ['listings'],
            },
        );
    }

    function reparse(listing: Listing, force = false) {
        router.post(
            `/discover/${listing.id}/reparse${force ? '?force=1' : ''}`,
            {},
            {
                preserveScroll: true,
                preserveState: true,
                only: ['listings'],
            },
        );
    }

    function saveFixture(listing: Listing) {
        router.post(
            `/discover/${listing.id}/save-fixture`,
            {},
            {
                preserveScroll: true,
                preserveState: true,
                only: ['listings'],
            },
        );
    }

    function saveCorrection(listing: Listing, field: EditableField, newValue: unknown) {
        router.post(
            `/discover/${listing.id}/corrections`,
            { field, new_value: newValue },
            {
                preserveScroll: true,
                preserveState: true,
                only: ['listings'],
                onSuccess: () => setEditing(null),
            },
        );
    }

    function applyFilters(patch: Partial<Filters>) {
        router.get('/discover', { ...filters, ...patch }, {
            replace: true,
            preserveScroll: true,
            preserveState: true,
            only: ['listings', 'filters'],
        });
    }

    function onSearchSubmit(e: FormEvent<HTMLFormElement>) {
        e.preventDefault();
        const form = new FormData(e.currentTarget);
        applyFilters({ q: ((form.get('q') as string) ?? '').trim() || null });
    }

    return (
        <AuthenticatedLayout>
            <Head title="Discover" />
            <div className="mx-auto max-w-6xl px-4 py-8 sm:px-6 lg:px-8">
                <header className="mb-6 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight text-foreground">Discover</h1>
                        <p className="text-sm text-muted-foreground">
                            <span className="font-mono tabular-nums">{listings.total}</span> {listings.total === 1 ? 'listing' : 'listings'} · J/↓ next · ↑ prev · K keep · X dismiss · S snooze · B bookmark
                        </p>
                    </div>
                    <form onSubmit={onSearchSubmit} className="flex gap-2">
                        <Input
                            name="q"
                            defaultValue={filters.q ?? ''}
                            placeholder="Company or role"
                            className="w-64"
                        />
                        <Button type="submit" variant="outline">Search</Button>
                    </form>
                </header>

                <StatusTabs current={filters.status} onChange={(status) => applyFilters({ status })} />

                <FilterBar filters={filters} options={options} onChange={applyFilters} />

                {listings.data.length === 0 ? (
                    <EmptyState />
                ) : (
                    <ul className="space-y-3">
                        {listings.data.map((listing, idx) => (
                            <li key={listing.id}>
                                <ListingCard
                                    ref={(el) => {
                                        cardRefs.current[idx] = el;
                                    }}
                                    listing={listing}
                                    isFocused={idx === focusIndex}
                                    isSelected={selectedIds.includes(listing.id)}
                                    onToggleSelect={() => toggleSelected(listing.id)}
                                    editingField={editing?.listingId === listing.id ? editing.field : null}
                                    onFocus={() => setFocusIndex(idx)}
                                    onAction={(verb) => actOn(listing, verb)}
                                    onEdit={(field) => setEditing({ listingId: listing.id, field })}
                                    onCancelEdit={() => setEditing(null)}
                                    onSave={(field, value) => saveCorrection(listing, field, value)}
                                    onSaveFixture={() => saveFixture(listing)}
                                    onReparse={(force) => reparse(listing, force)}
                                />
                            </li>
                        ))}
                    </ul>
                )}

                <Paginator paginator={listings} />

                <BulkActionBar
                    entity="aggregator_listing"
                    selectedIds={selectedIds}
                    onClear={() => setSelectedIds([])}
                    only={['listings', 'flash']}
                />
            </div>
        </AuthenticatedLayout>
    );
}

function StatusTabs({ current, onChange }: { current: string; onChange: (s: string) => void }) {
    return (
        <div className="mb-4 flex flex-wrap gap-1 border-b border-border">
            {STATUS_TABS.map((status) => {
                const active = current === status;
                return (
                    <button
                        key={status}
                        type="button"
                        onClick={() => onChange(status)}
                        className={
                            'px-3 py-2 text-sm capitalize transition-colors ' +
                            (active
                                ? 'border-b-2 border-primary text-foreground'
                                : 'text-muted-foreground hover:text-foreground')
                        }
                    >
                        {status.replace('_', ' ')}
                    </button>
                );
            })}
        </div>
    );
}

function FilterBar({
    filters,
    options,
    onChange,
}: {
    filters: Filters;
    options: Props['options'];
    onChange: (patch: Partial<Filters>) => void;
}) {
    return (
        <div className="mb-4 flex flex-wrap items-center gap-3 text-sm">
            <label className="flex items-center gap-2">
                <span className="text-muted-foreground">Source</span>
                <select
                    value={filters.source ?? ''}
                    onChange={(e) => onChange({ source: e.currentTarget.value || null })}
                    className="rounded-md border border-border bg-background px-2 py-1"
                >
                    <option value="">All</option>
                    {options.sources.map((s) => (
                        <option key={s.value} value={s.value}>
                            {s.label}
                        </option>
                    ))}
                </select>
            </label>
            <label className="flex items-center gap-2">
                <input
                    type="checkbox"
                    checked={filters.has_comp}
                    onChange={(e) => onChange({ has_comp: e.currentTarget.checked })}
                />
                Has comp
            </label>
            <label className="flex items-center gap-2">
                <input
                    type="checkbox"
                    checked={filters.remote_only}
                    onChange={(e) => onChange({ remote_only: e.currentTarget.checked })}
                />
                Remote only
            </label>
        </div>
    );
}

interface ListingCardProps {
    listing: Listing;
    isFocused: boolean;
    isSelected: boolean;
    onToggleSelect: () => void;
    editingField: EditableField | null;
    onFocus: () => void;
    onAction: (verb: 'keep' | 'dismiss' | 'snooze' | 'bookmark') => void;
    onEdit: (field: EditableField) => void;
    onCancelEdit: () => void;
    onSave: (field: EditableField, value: unknown) => void;
    onSaveFixture: () => void;
    onReparse: (force: boolean) => void;
    ref?: React.Ref<HTMLDivElement>;
}

function ListingCard({
    listing,
    isFocused,
    isSelected,
    onToggleSelect,
    editingField,
    onFocus,
    onAction,
    onEdit,
    onCancelEdit,
    onSave,
    onSaveFixture,
    onReparse,
    ref,
}: ListingCardProps) {
    const [menuOpen, setMenuOpen] = useState(false);
    return (
        <Card
            ref={ref}
            tabIndex={0}
            onClick={onFocus}
            className={
                'rounded-2xl transition-all hover:-translate-y-0.5 hover:shadow-md ' +
                (isSelected ? 'border-primary ring-1 ring-primary/40 ' : '') +
                (isFocused ? 'border-primary ring-1 ring-ring/30' : '')
            }
        >
            <CardContent className="space-y-2 p-4">
                <div className="flex items-baseline justify-between gap-4">
                    <div className="flex min-w-0 items-start gap-3">
                        <input
                            type="checkbox"
                            className="mt-1 size-4 shrink-0 rounded border-input"
                            aria-label={`Select ${listing.parsed_role_title ?? 'listing'}`}
                            checked={isSelected}
                            onClick={(e) => e.stopPropagation()}
                            onChange={(e) => {
                                e.stopPropagation();
                                onToggleSelect();
                            }}
                        />
                    <div className="min-w-0 space-y-1">
                        <div className="flex flex-wrap items-baseline gap-2">
                            <h3 className="text-base font-semibold">
                                {listing.url ? (
                                    <a
                                        href={listing.url}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        onClick={(e) => e.stopPropagation()}
                                        className="hover:underline"
                                    >
                                        {listing.parsed_role_title ?? '(role unknown)'}
                                    </a>
                                ) : (
                                    <span>{listing.parsed_role_title ?? '(role unknown)'}</span>
                                )}
                                <button
                                    type="button"
                                    onClick={(e) => {
                                        e.stopPropagation();
                                        onEdit('role_title');
                                    }}
                                    className="ml-1 text-xs text-muted-foreground/40 hover:text-foreground"
                                    title="Edit role title"
                                >
                                    ✎
                                </button>
                            </h3>
                            <EditablePill
                                listing={listing}
                                field="seniority"
                                label={listing.parsed_seniority ?? 'seniority?'}
                                emphasis="muted"
                                onEdit={onEdit}
                            />
                        </div>
                        <div className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                            <span className="font-medium text-foreground">
                                <EditablePill
                                    listing={listing}
                                    field="company_name"
                                    label={listing.parsed_company_name ?? '(company unknown)'}
                                    onEdit={onEdit}
                                />
                            </span>
                            {listing.source && (
                                <span className="rounded-full bg-secondary px-2 py-0.5 font-mono text-xs uppercase tracking-wide text-secondary-foreground">
                                    {listing.source.type}
                                </span>
                            )}
                            <EditablePill
                                listing={listing}
                                field="remote_type"
                                label={listing.parsed_remote_type?.replace('_', ' ') ?? 'remote?'}
                                emphasis="muted"
                                onEdit={onEdit}
                            />
                            <EditablePill
                                listing={listing}
                                field="region"
                                label={listing.location_bucket ?? 'region?'}
                                emphasis="muted"
                                onEdit={onEdit}
                            />
                            <EditablePill
                                listing={listing}
                                field="comp_min_usd"
                                label={
                                    listing.has_comp && listing.parsed_comp_min_usd != null
                                        ? `$${(listing.parsed_comp_min_usd / 1000).toFixed(0)}k${
                                              listing.parsed_comp_max_usd != null
                                                  ? `-$${(listing.parsed_comp_max_usd / 1000).toFixed(0)}k`
                                                  : ''
                                          }`
                                        : 'comp?'
                                }
                                emphasis="muted"
                                onEdit={onEdit}
                            />
                        </div>
                        {listing.stack_tags && listing.stack_tags.length > 0 && (
                            <div
                                className="flex flex-wrap gap-1 pt-1"
                                onClick={(e) => {
                                    e.stopPropagation();
                                    onEdit('stack_tags');
                                }}
                                role="button"
                                tabIndex={0}
                                title="Click to edit stack tags"
                            >
                                {listing.stack_tags.slice(0, 8).map((tag) => (
                                    <span
                                        key={tag}
                                        className="rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground"
                                    >
                                        {tag}
                                    </span>
                                ))}
                                <span className="text-xs text-muted-foreground/60 hover:text-foreground">edit</span>
                            </div>
                        )}
                        {listing.parser_version != null && (
                            <p className="pt-1 font-mono text-[10px] uppercase tracking-wide text-muted-foreground/60">
                                parsed by v<span className="tabular-nums">{listing.parser_version}</span>
                            </p>
                        )}
                    </div>
                    </div>
                    <div className="flex shrink-0 gap-1">
                        {listing.url && (
                            <Button
                                size="sm"
                                variant="outline"
                                asChild
                            >
                                <a
                                    href={listing.url}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    onClick={(e) => e.stopPropagation()}
                                >
                                    Open ↗
                                </a>
                            </Button>
                        )}
                        <Button
                            size="sm"
                            variant="default"
                            onClick={(e) => {
                                e.stopPropagation();
                                onAction('keep');
                            }}
                        >
                            Keep
                        </Button>
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={(e) => {
                                e.stopPropagation();
                                onAction('bookmark');
                            }}
                        >
                            Bookmark
                        </Button>
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={(e) => {
                                e.stopPropagation();
                                onAction('snooze');
                            }}
                        >
                            Snooze
                        </Button>
                        <Button
                            size="sm"
                            variant="ghost"
                            onClick={(e) => {
                                e.stopPropagation();
                                onAction('dismiss');
                            }}
                        >
                            Dismiss
                        </Button>
                    </div>
                </div>
                {listing.url && (
                    <a
                        href={listing.url}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="text-xs text-muted-foreground hover:text-foreground"
                        onClick={(e) => e.stopPropagation()}
                    >
                        {hostnameFromUrl(listing.url)}
                    </a>
                )}
                {editingField !== null && (
                    <CorrectionEditor
                        listing={listing}
                        field={editingField}
                        onSave={(value) => onSave(editingField, value)}
                        onCancel={onCancelEdit}
                        onSaveFixture={onSaveFixture}
                    />
                )}
                <div className="relative flex items-center justify-end gap-3">
                    <button
                        type="button"
                        onClick={(e) => {
                            e.stopPropagation();
                            onSaveFixture();
                        }}
                        className="font-mono text-[10px] uppercase tracking-wide text-muted-foreground/60 hover:text-foreground"
                        title="Save as test fixture"
                    >
                        save fixture
                    </button>
                    <button
                        type="button"
                        onClick={(e) => {
                            e.stopPropagation();
                            setMenuOpen((v) => !v);
                        }}
                        className="font-mono text-[10px] uppercase tracking-wide text-muted-foreground/60 hover:text-foreground"
                        title="More actions"
                        aria-expanded={menuOpen}
                    >
                        ⌄ more
                    </button>
                    {menuOpen && (
                        <div
                            className="absolute right-0 top-full z-10 mt-1 flex flex-col gap-1 rounded-md border border-border bg-popover p-1 text-xs text-popover-foreground shadow-md"
                            onClick={(e) => e.stopPropagation()}
                        >
                            <button
                                type="button"
                                onClick={() => {
                                    setMenuOpen(false);
                                    onReparse(false);
                                }}
                                className="rounded px-2 py-1 text-left hover:bg-muted"
                            >
                                Re-parse (keep corrections)
                            </button>
                            <button
                                type="button"
                                onClick={() => {
                                    setMenuOpen(false);
                                    onReparse(true);
                                }}
                                className="rounded px-2 py-1 text-left hover:bg-muted"
                            >
                                Re-parse (force — drop corrections)
                            </button>
                        </div>
                    )}
                </div>
            </CardContent>
        </Card>
    );
}

interface EditablePillProps {
    listing: Listing;
    field: EditableField;
    label: string;
    emphasis?: 'default' | 'muted';
    onEdit: (field: EditableField) => void;
}

function EditablePill({ listing, field, label, emphasis = 'default', onEdit }: EditablePillProps) {
    const slot = listing.parsed?.[PARSED_SLOT_FOR_FIELD[field]] ?? null;
    const confidence = slot?.confidence;
    const muted = emphasis === 'muted';
    const isNumeric = field === 'comp_min_usd' || field === 'comp_max_usd';

    return (
        <button
            type="button"
            onClick={(e) => {
                e.stopPropagation();
                onEdit(field);
            }}
            className={
                'group inline-flex items-center gap-1 rounded px-1 hover:bg-muted/60 ' +
                (muted ? 'font-mono text-xs uppercase tracking-wide text-muted-foreground' : '')
            }
            title={`Edit ${field.replace('_', ' ')}`}
        >
            <span className={isNumeric ? 'font-mono tabular-nums' : undefined}>{label}</span>
            {confidence != null && confidence < 0.7 && (
                <Badge variant="warning" className="hidden px-1 py-0 text-[10px] group-hover:inline-flex">
                    {Math.round(confidence * 100)}%
                </Badge>
            )}
            <span className="hidden text-[10px] text-muted-foreground/60 group-hover:inline">✎</span>
        </button>
    );
}

interface CorrectionEditorProps {
    listing: Listing;
    field: EditableField;
    onSave: (value: unknown) => void;
    onCancel: () => void;
    onSaveFixture: () => void;
}

function CorrectionEditor({ listing, field, onSave, onCancel, onSaveFixture }: CorrectionEditorProps) {
    const slot = listing.parsed?.[PARSED_SLOT_FOR_FIELD[field]] ?? null;
    const currentValue = currentValueFor(listing, field);
    const [value, setValue] = useState<unknown>(currentValue);
    const [showRaw, setShowRaw] = useState(false);

    function submit(e: FormEvent<HTMLFormElement>) {
        e.preventDefault();
        onSave(value);
    }

    return (
        <form
            onSubmit={submit}
            onClick={(e) => e.stopPropagation()}
            className="mt-2 space-y-2 rounded-2xl border border-border bg-muted/30 p-3 text-sm"
        >
            <div className="flex flex-wrap items-center gap-2">
                <span className="font-medium">Edit {field.replace('_', ' ')}</span>
                {slot && (
                    <>
                        <Badge variant={slot.confidence >= 0.7 ? 'success' : 'warning'}>
                            confidence <span className="font-mono tabular-nums">{(slot.confidence * 100).toFixed(0)}%</span>
                        </Badge>
                        <Badge variant="outline">source: {slot.source}</Badge>
                    </>
                )}
            </div>

            {slot?.evidence && (
                <p className="rounded-md bg-background px-2 py-1 text-xs text-muted-foreground">
                    <span className="font-medium text-foreground">evidence:</span> {slot.evidence}
                </p>
            )}

            <FieldInput field={field} value={value} onChange={setValue} />

            <div className="flex items-center gap-2 pt-1">
                <Button type="submit" size="sm">
                    Save
                </Button>
                <Button type="button" size="sm" variant="ghost" onClick={onCancel}>
                    Cancel
                </Button>
                <Button type="button" size="sm" variant="outline" onClick={onSaveFixture}>
                    Save as fixture
                </Button>
                <button
                    type="button"
                    onClick={() => setShowRaw((v) => !v)}
                    className="ml-auto text-xs text-muted-foreground hover:text-foreground"
                >
                    {showRaw ? 'Hide raw' : 'View raw'}
                </button>
            </div>

            {showRaw && (
                <pre className="max-h-64 overflow-auto rounded-md border border-border bg-background p-2 font-mono text-[11px] leading-tight">
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
    value: unknown;
    onChange: (v: unknown) => void;
}) {
    if (field === 'remote_type') {
        return (
            <select
                value={typeof value === 'string' ? value : 'unknown'}
                onChange={(e) => onChange(e.currentTarget.value)}
                className="w-full rounded-md border border-border bg-background px-2 py-1"
            >
                <option value="remote">remote</option>
                <option value="hybrid">hybrid</option>
                <option value="on_site">on_site</option>
                <option value="unknown">unknown</option>
            </select>
        );
    }
    if (field === 'comp_min_usd' || field === 'comp_max_usd') {
        return (
            <Input
                type="number"
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
            value={typeof value === 'string' ? value : ''}
            onChange={(e) => onChange(e.currentTarget.value)}
        />
    );
}

function hostnameFromUrl(url: string): string {
    try {
        return new URL(url).hostname.replace(/^www\./, '');
    } catch {
        return url;
    }
}

function currentValueFor(listing: Listing, field: EditableField): unknown {
    switch (field) {
        case 'company_name':
            return listing.parsed_company_name ?? '';
        case 'role_title':
            return listing.parsed_role_title ?? '';
        case 'seniority':
            return listing.parsed_seniority ?? '';
        case 'remote_type':
            return listing.parsed_remote_type ?? 'unknown';
        case 'region':
            return listing.location_bucket ?? '';
        case 'comp_min_usd':
            return listing.parsed_comp_min_usd;
        case 'comp_max_usd':
            return listing.parsed_comp_max_usd;
        case 'stack_tags':
            return listing.stack_tags ?? [];
    }
}

function EmptyState() {
    return (
        <Card>
            <CardContent className="py-12 text-center text-sm text-muted-foreground">
                Nothing in this view. Try a different status tab or run the scrapers.
            </CardContent>
        </Card>
    );
}

function Paginator<T>({ paginator }: { paginator: Paginator<T> }) {
    if (paginator.last_page <= 1) return null;

    return (
        <div className="mt-6 flex items-center justify-between text-sm text-muted-foreground">
            <span>
                Page <span className="font-mono tabular-nums">{paginator.current_page}</span> of{' '}
                <span className="font-mono tabular-nums">{paginator.last_page}</span>
            </span>
            <div className="flex gap-2">
                {paginator.prev_page_url && (
                    <Link
                        href={paginator.prev_page_url}
                        preserveScroll
                        preserveState
                        only={['listings']}
                        className="rounded-md border border-border px-3 py-1 hover:bg-muted"
                    >
                        Prev
                    </Link>
                )}
                {paginator.next_page_url && (
                    <Link
                        href={paginator.next_page_url}
                        preserveScroll
                        preserveState
                        only={['listings']}
                        className="rounded-md border border-border px-3 py-1 hover:bg-muted"
                    >
                        Next
                    </Link>
                )}
            </div>
        </div>
    );
}
