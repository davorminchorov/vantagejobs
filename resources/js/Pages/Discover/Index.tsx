import { Head, InfiniteScroll, router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { ChevronDown, MoreHorizontal, Pencil } from 'lucide-react';

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import BulkActionBar from '@/components/bulk/BulkActionBar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import PageHeader from '@/components/PageHeader';
import { RelativeDate } from '@/lib/dates';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

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
    auto_dismiss_reason: string | null;
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
    /** Per-status row counts, zero-filled for every status, scoped by the current non-status filters. */
    counts: Record<string, number>;
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

/** Values the inline correction editor can produce for any editable field. */
type CorrectionValue = string | number | string[] | null;

const VISIBLE_STATUS_TABS = ['new', 'kept', 'snoozed', 'dismissed'] as const;
const MORE_STATUS_TABS = ['linked', 'auto_dismissed', 'overflow', 'duplicate', 'expired'] as const;

function titleCaseStatus(status: string): string {
    return status
        .split('_')
        .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
        .join(' ');
}

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

export default function DiscoverIndex({ listings, filters, counts, options }: Props) {
    const { debug } = usePage<PageProps>().props;
    const [focusIndex, setFocusIndex] = useState<number>(-1);
    const [editing, setEditing] = useState<{ listingId: number; field: EditableField } | null>(null);
    const [selectedIds, setSelectedIds] = useState<number[]>([]);
    const cardRefs = useRef<Array<HTMLLIElement | null>>([]);
    const itemsRef = useRef<HTMLUListElement | null>(null);

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
            // contenteditable (the correction editor lives inside rows) or
            // while focus sits inside an open menu (Radix moves focus onto
            // div[role=menuitem]s, which would otherwise let k/x/s/b fall
            // through to the row underneath).
            const target = e.target as HTMLElement | null;
            if (
                target &&
                (target.tagName === 'INPUT' ||
                    target.tagName === 'TEXTAREA' ||
                    target.isContentEditable ||
                    target.closest('[role="menu"], [role="menuitem"]') !== null)
            ) {
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
        // actOn is recreated each render but only reads state the deps already cover.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [focusIndex, listings.data, editing]);

    useEffect(() => {
        if (focusIndex >= 0) {
            const prefersReducedMotion =
                typeof window !== 'undefined' &&
                typeof window.matchMedia === 'function' &&
                window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            cardRefs.current[focusIndex]?.scrollIntoView({
                block: 'nearest',
                behavior: prefersReducedMotion ? 'auto' : 'smooth',
            });
        }
    }, [focusIndex]);

    /** Removes a listing from the merged `listings` prop client-side and reports the new total. */
    function removeListingFromProp(old: unknown, id: number): Paginator<Listing> {
        const paginator = old as Paginator<Listing>;
        return {
            ...paginator,
            data: paginator.data.filter((l) => l.id !== id),
            total: Math.max(0, paginator.total - 1),
        };
    }

    /** Clamps focus onto whatever now sits at (or nearest to) the removed item's index. */
    function refocusAfterRemoval(removedIndex: number) {
        const newLength = listings.data.length - 1;
        setFocusIndex(newLength <= 0 ? -1 : Math.min(removedIndex, newLength - 1));
    }

    function actOn(listing: Listing, verb: 'keep' | 'dismiss' | 'snooze' | 'bookmark') {
        const removedIndex = listings.data.findIndex((l) => l.id === listing.id);
        router.post(
            `/discover/${listing.id}/${verb}`,
            {},
            {
                preserveScroll: true,
                preserveState: true,
                only: ['counts', 'flash'],
                onSuccess: () => {
                    router.replaceProp('listings', (old: unknown) => removeListingFromProp(old, listing.id));
                    refocusAfterRemoval(removedIndex);
                },
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
                reset: ['listings'],
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
                reset: ['listings'],
            },
        );
    }

    function saveCorrection(listing: Listing, field: EditableField, newValue: CorrectionValue) {
        router.post(
            `/discover/${listing.id}/corrections`,
            { field, new_value: newValue },
            {
                preserveScroll: true,
                preserveState: true,
                only: ['listings'],
                reset: ['listings'],
                onSuccess: () => setEditing(null),
            },
        );
    }

    function applyFilters(patch: Partial<Filters>) {
        router.get('/discover', { ...filters, ...patch }, {
            replace: true,
            preserveScroll: true,
            preserveState: true,
            only: ['listings', 'filters', 'counts'],
            reset: ['listings'],
        });
    }

    /** Bulk completion: drops every affected row from the merged list, mirroring actOn's single-row removal. */
    function onBulkApplied() {
        router.replaceProp('listings', (old: unknown) => {
            const paginator = old as Paginator<Listing>;
            const remaining = paginator.data.filter((l) => !selectedIds.includes(l.id));
            return {
                ...paginator,
                data: remaining,
                total: Math.max(0, paginator.total - (paginator.data.length - remaining.length)),
            };
        });
        setFocusIndex(-1);
    }

    function onSearchSubmit(e: FormEvent<HTMLFormElement>) {
        e.preventDefault();
        const form = new FormData(e.currentTarget);
        applyFilters({ q: ((form.get('q') as string) ?? '').trim() || null });
    }

    return (
        <AuthenticatedLayout>
            <Head title="Discover" />
            <div className="flex flex-col gap-6">
                <PageHeader
                    title="Discover"
                    subtitle={
                        <>
                            Showing <span className="tabular-nums">{listings.data.length}</span> of{' '}
                            <span className="tabular-nums">{listings.total}</span> {listings.total === 1 ? 'listing' : 'listings'}
                            <span className="hidden md:inline"> · J/↓ next · ↑ prev · K keep · X dismiss · S snooze · B bookmark</span>
                        </>
                    }
                    actions={
                        <form onSubmit={onSearchSubmit} className="flex w-full gap-2 sm:w-auto" role="search">
                            <Input
                                name="q"
                                aria-label="Search by company or role"
                                defaultValue={filters.q ?? ''}
                                placeholder="Company or role"
                                className="w-full min-w-0 sm:w-64"
                            />
                            <Button type="submit" variant="outline" className="shrink-0">
                                Search
                            </Button>
                        </form>
                    }
                />

                <div className="flex flex-col gap-3">
                <StatusTabs current={filters.status} counts={counts} onChange={(status) => applyFilters({ status })} />

                <FilterBar filters={filters} options={options} onChange={applyFilters} />
                </div>

                {listings.total === 0 ? (
                    <DiscoverEmptyState />
                ) : (
                    <div>
                    <InfiniteScroll
                        data="listings"
                        manual
                        itemsElement={itemsRef}
                        next={({ loading, fetch, hasMore }) =>
                            hasMore ? (
                                <div className="flex justify-center pt-6">
                                    <Button variant="outline" onClick={fetch} disabled={loading}>
                                        {loading ? 'Loading…' : `Load more (${listings.total - listings.data.length} remaining)`}
                                    </Button>
                                </div>
                            ) : null
                        }
                    >
                        {listings.data.length === 0 && (
                            <p className="py-8 text-center text-base text-pretty text-muted-foreground sm:text-sm">
                                Nothing left on this page. Load more below.
                            </p>
                        )}
                        <ul
                            ref={itemsRef}
                            role="list"
                            className="divide-y divide-border border-y border-border"
                        >
                            {listings.data.map((listing, idx) => (
                                <ListingRow
                                    key={listing.id}
                                    ref={(el) => {
                                        cardRefs.current[idx] = el;
                                    }}
                                    listing={listing}
                                    debug={debug}
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
                            ))}
                        </ul>
                    </InfiniteScroll>
                    </div>
                )}

                <BulkActionBar
                    entity="aggregator_listing"
                    selectedIds={selectedIds}
                    onClear={() => setSelectedIds([])}
                    onApplied={onBulkApplied}
                    only={['counts', 'flash']}
                />
            </div>
        </AuthenticatedLayout>
    );
}

function StatusTabs({
    current,
    counts,
    onChange,
}: {
    current: string;
    counts: Record<string, number>;
    onChange: (s: string) => void;
}) {
    const moreActive = (MORE_STATUS_TABS as readonly string[]).includes(current);

    return (
        <div className="flex items-center gap-1 overflow-x-auto shadow-[inset_0_-1px_0_var(--border)]">
            {VISIBLE_STATUS_TABS.map((status) => {
                const active = current === status;
                return (
                    <button
                        key={status}
                        type="button"
                        onClick={() => onChange(status)}
                        aria-current={active ? 'true' : undefined}
                        className={
                            'min-h-10 shrink-0 border-b-2 px-3 py-2 text-base whitespace-nowrap sm:min-h-0 sm:text-sm ' +
                            (active
                                ? 'border-foreground font-medium text-foreground'
                                : 'border-transparent text-muted-foreground hover:text-foreground')
                        }
                    >
                        {titleCaseStatus(status)}{' '}
                        <span className="text-xs tabular-nums text-muted-foreground">{counts[status] ?? 0}</span>
                    </button>
                );
            })}
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <button
                        type="button"
                        className={
                            'min-h-10 shrink-0 border-b-2 px-3 py-2 text-base whitespace-nowrap sm:min-h-0 sm:text-sm ' +
                            (moreActive
                                ? 'border-foreground font-medium text-foreground'
                                : 'border-transparent text-muted-foreground hover:text-foreground')
                        }
                        aria-label="More status tabs"
                    >
                        {moreActive ? (
                            <>
                                {titleCaseStatus(current)}{' '}
                                <span className="text-xs tabular-nums text-muted-foreground">{counts[current] ?? 0}</span>
                            </>
                        ) : (
                            'More'
                        )}{' '}
                        <ChevronDown aria-hidden="true" className="inline size-4 align-text-bottom" />
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="start">
                    {MORE_STATUS_TABS.map((status) => (
                        <DropdownMenuItem key={status} onSelect={() => onChange(status)}>
                            {titleCaseStatus(status)}{' '}
                            <span className="ml-1 text-xs tabular-nums text-muted-foreground">{counts[status] ?? 0}</span>
                        </DropdownMenuItem>
                    ))}
                </DropdownMenuContent>
            </DropdownMenu>
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
        <div className="flex flex-wrap items-center gap-x-5 gap-y-3 text-base sm:text-sm">
            <label className="flex items-center gap-2">
                <span className="text-muted-foreground">Source</span>
                <NativeSelect
                    name="source"
                    value={filters.source ?? ''}
                    onChange={(e) => onChange({ source: e.currentTarget.value || null })}
                >
                    <option value="">All</option>
                    {options.sources.map((s) => (
                        <option key={s.value} value={s.value}>
                            {s.label}
                        </option>
                    ))}
                </NativeSelect>
            </label>
            <label className="flex min-h-10 items-center gap-2 sm:min-h-0">
                <input
                    type="checkbox"
                    name="has_comp"
                    className="size-4 accent-primary"
                    checked={filters.has_comp}
                    onChange={(e) => onChange({ has_comp: e.currentTarget.checked })}
                />
                Has comp
            </label>
            <label className="flex min-h-10 items-center gap-2 sm:min-h-0">
                <input
                    type="checkbox"
                    name="remote_only"
                    className="size-4 accent-primary"
                    checked={filters.remote_only}
                    onChange={(e) => onChange({ remote_only: e.currentTarget.checked })}
                />
                Remote only
            </label>
        </div>
    );
}

interface ListingRowProps {
    listing: Listing;
    debug: boolean;
    isFocused: boolean;
    isSelected: boolean;
    onToggleSelect: () => void;
    editingField: EditableField | null;
    onFocus: () => void;
    onAction: (verb: 'keep' | 'dismiss' | 'snooze' | 'bookmark') => void;
    onEdit: (field: EditableField) => void;
    onCancelEdit: () => void;
    onSave: (field: EditableField, value: CorrectionValue) => void;
    onSaveFixture: () => void;
    onReparse: (force: boolean) => void;
    ref?: React.Ref<HTMLLIElement>;
}

function ListingRow({
    listing,
    debug,
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
}: ListingRowProps) {
    const hnCommentUrl = hackerNewsCommentUrl(listing);
    const titleHref = listing.url ?? hnCommentUrl;

    return (
        <li
            ref={ref}
            tabIndex={0}
            onClick={onFocus}
            aria-label={`Listing: ${listing.parsed_role_title ?? 'unknown role'} at ${listing.parsed_company_name ?? 'unknown company'}`}
            className={cn(
                // group/row is named so the pill-level plain `group` (hover
                // pencil reveal) doesn't also fire on row hover.
                'group/row relative outline-none',
                'hover:bg-muted/50',
                'focus-within:bg-muted focus-within:ring-1 focus-within:ring-inset focus-within:ring-border',
                isSelected && 'bg-muted ring-1 ring-inset ring-border',
                isFocused && 'bg-muted ring-1 ring-inset ring-ring/40',
            )}
        >
            <div className="grid grid-cols-[auto_1fr] items-start gap-x-3 gap-y-2 px-1 py-3 sm:grid-cols-[auto_1fr_auto] sm:px-3">
                <input
                    type="checkbox"
                    className="mt-1 size-4 accent-primary"
                    aria-label={`Select ${listing.parsed_role_title ?? 'listing'}`}
                    checked={isSelected}
                    onClick={(e) => e.stopPropagation()}
                    onChange={(e) => {
                        e.stopPropagation();
                        onToggleSelect();
                    }}
                />
                <div className="min-w-0">
                    <div className="flex flex-wrap items-baseline gap-2 text-base sm:flex-nowrap sm:text-sm">
                        <h3 className="min-w-0 text-base font-medium text-pretty sm:truncate sm:text-sm">
                            {titleHref ? (
                                <a
                                    href={titleHref}
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
                        </h3>
                        <button
                            type="button"
                            onClick={(e) => {
                                e.stopPropagation();
                                onEdit('role_title');
                            }}
                            className={cn(
                                'invisible shrink-0 self-center rounded-md p-0.5 text-muted-foreground hover:text-foreground focus-visible:outline-2 focus-visible:outline-ring group-hover/row:visible',
                                isFocused ? 'visible' : 'max-sm:hidden',
                            )}
                            aria-label="Edit role title"
                            title="Edit role title"
                        >
                            <Pencil aria-hidden="true" className="size-3.5" />
                        </button>
                        <EditablePill
                            listing={listing}
                            field="seniority"
                            label={listing.parsed_seniority ?? 'add seniority'}
                            emphasis="muted"
                            placeholder={listing.parsed_seniority == null}
                            revealed={isFocused}
                            className="shrink-0"
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
                            placeholder={!listing.has_comp || listing.parsed_comp_min_usd == null}
                            revealed={isFocused}
                            className="shrink-0"
                            onEdit={onEdit}
                        />
                    </div>
                    <div className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-muted-foreground">
                        <div className="font-medium text-foreground">
                            <EditablePill
                                listing={listing}
                                field="company_name"
                                label={listing.parsed_company_name ?? '(company unknown)'}
                                onEdit={onEdit}
                            />
                        </div>
                        {listing.source &&
                            (listing.url ? (
                                <a
                                    href={listing.url}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    title={hostnameFromUrl(listing.url)}
                                    onClick={(e) => e.stopPropagation()}
                                    className="hover:underline"
                                >
                                    <Badge variant="secondary">{listing.source.type}</Badge>
                                </a>
                            ) : (
                                <Badge variant="secondary">{listing.source.type}</Badge>
                            ))}
                        {listing.auto_dismiss_reason ? (
                            <Badge variant="warning">
                                {AUTO_DISMISS_REASON_LABELS[listing.auto_dismiss_reason] ?? listing.auto_dismiss_reason.replace('_', ' ')}
                            </Badge>
                        ) : null}
                        <EditablePill
                            listing={listing}
                            field="remote_type"
                            label={listing.parsed_remote_type?.replace('_', ' ') ?? 'add remote type'}
                            emphasis="muted"
                            placeholder={listing.parsed_remote_type == null}
                            revealed={isFocused}
                            onEdit={onEdit}
                        />
                        <EditablePill
                            listing={listing}
                            field="region"
                            label={listing.location_bucket ?? 'add region'}
                            emphasis="muted"
                            placeholder={listing.location_bucket == null}
                            revealed={isFocused}
                            onEdit={onEdit}
                        />
                        {listing.posted_at !== null && (
                            <div className="text-xs whitespace-nowrap">
                                posted <RelativeDate iso={listing.posted_at} />
                            </div>
                        )}
                        {listing.stack_tags && listing.stack_tags.length > 0 && (
                            <div
                                className="flex flex-wrap items-center gap-1 rounded-md focus-visible:outline-2 focus-visible:outline-ring"
                                onClick={(e) => {
                                    e.stopPropagation();
                                    onEdit('stack_tags');
                                }}
                                onKeyDown={(e) => {
                                    if (e.key === 'Enter' || e.key === ' ') {
                                        e.preventDefault();
                                        e.stopPropagation();
                                        onEdit('stack_tags');
                                    }
                                }}
                                role="button"
                                tabIndex={0}
                                aria-label="Edit stack tags"
                            >
                                {listing.stack_tags.slice(0, 8).map((tag) => (
                                    <Badge key={tag} variant="secondary">
                                        {tag}
                                    </Badge>
                                ))}
                                <span
                                    className={cn(
                                        'invisible text-xs text-muted-foreground group-hover/row:visible',
                                        isFocused && 'visible',
                                    )}
                                >
                                    edit
                                </span>
                            </div>
                        )}
                    </div>
                </div>
                <div
                    className={cn(
                        // visibility (not opacity/display) keeps the cluster's
                        // width reserved so rows never shift, and drops hidden
                        // buttons out of tab order and hit-testing. Below sm
                        // nothing hides (touch needs always-visible targets)
                        // and the cluster wraps onto its own full-width row.
                        'col-span-2 flex flex-wrap items-center gap-2 sm:col-span-1 sm:flex-nowrap sm:gap-1',
                        'sm:invisible group-hover/row:sm:visible group-focus-within/row:sm:visible',
                        isFocused && 'sm:visible',
                    )}
                >
                    {listing.url && (
                        <Button
                            size="sm"
                            variant="outline"
                            className="min-h-11 sm:min-h-0"
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
                    {hnCommentUrl && (
                        <Button
                            size="sm"
                            variant="outline"
                            className="min-h-11 sm:min-h-0"
                            asChild
                        >
                            <a
                                href={hnCommentUrl}
                                target="_blank"
                                rel="noopener noreferrer"
                                title="View this posting on Hacker News"
                                onClick={(e) => e.stopPropagation()}
                            >
                                HN ↗
                            </a>
                        </Button>
                    )}
                    <Button
                        size="sm"
                        variant="outline"
                        className="min-h-11 sm:min-h-0"
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
                        className="min-h-11 sm:min-h-0"
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
                        className="min-h-11 sm:min-h-0"
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
                        className="min-h-11 sm:min-h-0"
                        onClick={(e) => {
                            e.stopPropagation();
                            onAction('dismiss');
                        }}
                    >
                        Dismiss
                    </Button>
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button
                                size="sm"
                                variant="ghost"
                                aria-label="More actions"
                                className="min-h-11 px-2 data-[state=open]:sm:visible sm:min-h-0"
                                onClick={(e) => e.stopPropagation()}
                            >
                                <MoreHorizontal aria-hidden="true" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" onClick={(e) => e.stopPropagation()}>
                            <DropdownMenuItem onSelect={() => onReparse(false)}>
                                Re-parse (keep corrections)
                            </DropdownMenuItem>
                            <DropdownMenuItem onSelect={() => onReparse(true)}>
                                Re-parse (force, drop corrections)
                            </DropdownMenuItem>
                            {debug && (
                                <DropdownMenuItem onSelect={onSaveFixture}>Save as fixture</DropdownMenuItem>
                            )}
                            {debug && listing.parser_version != null && (
                                <div className="px-2 py-1.5 text-xs text-muted-foreground">
                                    Parsed by v<span className="tabular-nums">{listing.parser_version}</span>
                                </div>
                            )}
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </div>
            {editingField !== null && (
                <div className="px-1 pb-3 sm:px-3">
                    <CorrectionEditor
                        listing={listing}
                        field={editingField}
                        debug={debug}
                        onSave={(value) => onSave(editingField, value)}
                        onCancel={onCancelEdit}
                        onSaveFixture={onSaveFixture}
                    />
                </div>
            )}
        </li>
    );
}

interface EditablePillProps {
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

function EditablePill({
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

interface CorrectionEditorProps {
    listing: Listing;
    field: EditableField;
    debug: boolean;
    onSave: (value: CorrectionValue) => void;
    onCancel: () => void;
    onSaveFixture: () => void;
}

function CorrectionEditor({ listing, field, debug, onSave, onCancel, onSaveFixture }: CorrectionEditorProps) {
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

const AUTO_DISMISS_REASON_LABELS: Record<string, string> = {
    job_seeker: 'looks like a job seeker',
};

/** The posting's own comment on Hacker News (HN listings use the comment id as external_id). */
function hackerNewsCommentUrl(listing: Listing): string | null {
    if (listing.source?.type !== 'hackernews' || !listing.external_id) {
        return null;
    }
    return `https://news.ycombinator.com/item?id=${encodeURIComponent(listing.external_id)}`;
}

function hostnameFromUrl(url: string): string {
    try {
        return new URL(url).hostname.replace(/^www\./, '');
    } catch {
        return url;
    }
}

function currentValueFor(listing: Listing, field: EditableField): CorrectionValue {
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

function DiscoverEmptyState() {
    return <EmptyState>Nothing in this view. Try a different status tab or run the scrapers.</EmptyState>;
}

