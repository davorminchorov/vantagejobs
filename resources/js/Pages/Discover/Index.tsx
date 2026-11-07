import { Head, InfiniteScroll, router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import type { FormEvent } from 'react';

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import BulkActionBar from '@/components/bulk/BulkActionBar';
import { DiscoverEmptyState } from '@/components/discover/DiscoverEmptyState';
import { FilterBar } from '@/components/discover/FilterBar';
import { SplitViewLayout } from '@/components/discover/SplitViewLayout';
import { StatusTabs } from '@/components/discover/StatusTabs';
import { hackerNewsCommentUrl, postingUrl } from '@/components/discover/helpers';
import type {
    CorrectionValue,
    DiscoverLayoutProps,
    DiscoverOptions,
    EditableField,
    EditingState,
    Filters,
    Listing,
    ListingAction,
    Paginator,
} from '@/components/discover/types';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import PageHeader from '@/components/PageHeader';
import type { PageProps } from '@/types';

interface Props {
    listings: Paginator<Listing>;
    filters: Filters;
    /** Per-status row counts, zero-filled for every status, scoped by the current non-status filters. */
    counts: Record<string, number>;
    options: DiscoverOptions;
}

export default function DiscoverIndex({ listings, filters, counts, options }: Props) {
    const { debug } = usePage<PageProps>().props;
    const [focusIndex, setFocusIndex] = useState<number>(-1);
    const [editing, setEditing] = useState<EditingState | null>(null);
    const [selectedIds, setSelectedIds] = useState<number[]>([]);
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

    function selectMany(ids: number[]) {
        setSelectedIds((prev) => [...prev, ...ids.filter((id) => !prev.includes(id))]);
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
            } else if (e.key === 'o') {
                const href = postingUrl(listing) ?? hackerNewsCommentUrl(listing);
                if (href) {
                    e.preventDefault();
                    window.open(href, '_blank', 'noopener,noreferrer');
                }
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

    function actOn(listing: Listing, verb: ListingAction) {
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

    function reparse(listing: Listing, force: boolean) {
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

    const layoutProps: DiscoverLayoutProps = {
        listings: listings.data,
        debug,
        focusIndex,
        setFocusIndex,
        selectedIds,
        toggleSelected,
        selectMany,
        editing,
        setEditing,
        actOn,
        saveCorrection,
        saveFixture,
        reparse,
        itemsRef,
    };

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
                            <span className="hidden md:inline"> · J/↓ next · ↑ prev · K keep · X dismiss · S snooze · B bookmark · O open</span>
                        </>
                    }
                    actions={
                        <form onSubmit={onSearchSubmit} className="flex w-full gap-2 sm:w-auto" role="search">
                            <Input
                                key={filters.q ?? ''}
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
                        <SplitViewLayout {...layoutProps} />
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
