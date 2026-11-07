import { useEffect, useRef, useState } from 'react';

import { Sheet, SheetContent, SheetDescription, SheetTitle } from '@/components/ui/sheet';
import { formatRelative } from '@/lib/dates';
import { cn } from '@/lib/utils';

import { ListingDetail } from './ListingDetail';
import type { DiscoverLayoutProps, Listing } from './types';
import { useIsDesktop } from './useIsDesktop';
import { useScrollFocusedIntoView } from './useScrollFocusedIntoView';

/**
 * Discover triage layout: a compact list on the left and the focused
 * listing's details (description included) on the right. Below `lg` the list
 * takes the full width and tapping a row opens the details in a sheet.
 */
export function SplitViewLayout({
    listings,
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
}: DiscoverLayoutProps) {
    const isDesktop = useIsDesktop();
    const [sheetOpen, setSheetOpen] = useState(false);
    const rowRefs = useRef<Array<HTMLLIElement | null>>([]);
    const paneRef = useRef<HTMLDivElement | null>(null);
    useScrollFocusedIntoView(rowRefs, focusIndex);

    const shownIndex = focusIndex >= 0 ? focusIndex : isDesktop ? 0 : -1;
    const shown = shownIndex >= 0 ? listings[shownIndex] : undefined;

    // On desktop something is always in the pane, so keyboard actions should
    // always have a target too.
    useEffect(() => {
        if (isDesktop && focusIndex < 0 && listings.length > 0) {
            setFocusIndex(0);
        }
    }, [isDesktop, focusIndex, listings.length, setFocusIndex]);

    useEffect(() => {
        paneRef.current?.scrollTo({ top: 0 });
    }, [shown?.id]);

    useEffect(() => {
        if (isDesktop) {
            setSheetOpen(false);
        }
    }, [isDesktop]);

    function sameCompanyIds(listing: Listing): number[] {
        const company = listing.parsed_company_name?.trim().toLowerCase();
        if (!company) {
            return [];
        }
        return listings
            .filter(
                (other) =>
                    other.id !== listing.id &&
                    other.parsed_company_name?.trim().toLowerCase() === company &&
                    !selectedIds.includes(other.id),
            )
            .map((other) => other.id);
    }

    function detailFor(listing: Listing) {
        const others = sameCompanyIds(listing);
        return (
            <ListingDetail
                listing={listing}
                debug={debug}
                editingField={editing?.listingId === listing.id ? editing.field : null}
                sameCompanyIds={others}
                onAction={(verb) => actOn(listing, verb)}
                onEdit={(field) => setEditing({ listingId: listing.id, field })}
                onCancelEdit={() => setEditing(null)}
                onSave={(field, value) => saveCorrection(listing, field, value)}
                onSaveFixture={() => saveFixture(listing)}
                onReparse={(force) => reparse(listing, force)}
                onSelectSameCompany={() => selectMany(selectedIds.includes(listing.id) ? others : [listing.id, ...others])}
            />
        );
    }

    return (
        <div className="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(20rem,26rem)_minmax(0,1fr)] lg:items-start">
            <ul
                ref={itemsRef}
                role="list"
                aria-label="Listings"
                className="divide-y divide-border border-y border-border lg:flex lg:flex-col lg:gap-0.5 lg:divide-y-0 lg:border-y-0"
            >
                {listings.map((listing, idx) => (
                    <CompactRow
                        key={listing.id}
                        ref={(el) => {
                            rowRefs.current[idx] = el;
                        }}
                        listing={listing}
                        isFocused={idx === shownIndex}
                        isSelected={selectedIds.includes(listing.id)}
                        onToggleSelect={() => toggleSelected(listing.id)}
                        onOpen={() => {
                            setFocusIndex(idx);
                            if (!isDesktop) {
                                setSheetOpen(true);
                            }
                        }}
                    />
                ))}
            </ul>

            {isDesktop ? (
                <div ref={paneRef} className="sticky top-6 max-h-[calc(100dvh-3rem)] overflow-y-auto rounded-xl border border-border p-6">
                    {shown ? detailFor(shown) : null}
                </div>
            ) : (
                <Sheet open={sheetOpen && shown !== undefined} onOpenChange={setSheetOpen}>
                    <SheetContent side="right" className="w-full overflow-y-auto p-4 pt-14 sm:max-w-xl sm:p-6 sm:pt-14">
                        <SheetTitle className="sr-only">{shown?.parsed_role_title ?? 'Listing'}</SheetTitle>
                        <SheetDescription className="sr-only">Details, actions and description for the selected listing.</SheetDescription>
                        {shown ? detailFor(shown) : null}
                    </SheetContent>
                </Sheet>
            )}
        </div>
    );
}

interface CompactRowProps {
    listing: Listing;
    isFocused: boolean;
    isSelected: boolean;
    onToggleSelect: () => void;
    onOpen: () => void;
    ref?: React.Ref<HTMLLIElement>;
}

function CompactRow({ listing, isFocused, isSelected, onToggleSelect, onOpen, ref }: CompactRowProps) {
    const comp =
        listing.has_comp && listing.parsed_comp_min_usd != null
            ? `$${Math.round(listing.parsed_comp_min_usd / 1000)}k${
                  listing.parsed_comp_max_usd != null ? `-${Math.round(listing.parsed_comp_max_usd / 1000)}k` : ''
              }`
            : null;
    const meta = [listing.parsed_company_name ?? 'Unknown company', comp, listing.parsed_remote_type?.replace('_', ' ')]
        .filter(Boolean)
        .join(' · ');

    return (
        <li
            ref={ref}
            className={cn(
                'relative flex items-start gap-3 px-1 py-3 sm:px-3 lg:rounded-lg lg:py-2.5',
                isFocused ? 'bg-muted' : 'hover:bg-muted/50',
                isSelected && !isFocused && 'bg-primary/5',
            )}
        >
            <input
                type="checkbox"
                className="relative z-10 mt-1 size-4 shrink-0 accent-primary"
                aria-label={`Select ${listing.parsed_role_title ?? 'listing'}`}
                checked={isSelected}
                onChange={onToggleSelect}
            />
            <button
                type="button"
                onClick={onOpen}
                aria-current={isFocused ? 'true' : undefined}
                className="min-w-0 flex-1 text-left outline-none after:absolute after:inset-0 after:rounded-lg focus-visible:after:outline-2 focus-visible:after:outline-ring"
            >
                <span className="flex items-baseline gap-3">
                    <span className="min-w-0 flex-1 truncate text-base font-medium sm:text-sm">
                        {listing.parsed_role_title ?? '(role unknown)'}
                    </span>
                    {listing.posted_at !== null ? (
                        <span className="shrink-0 text-xs text-muted-foreground tabular-nums">{formatRelative(listing.posted_at)}</span>
                    ) : null}
                </span>
                <span className="mt-0.5 block truncate text-sm text-muted-foreground">{meta}</span>
            </button>
        </li>
    );
}
