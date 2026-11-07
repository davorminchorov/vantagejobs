import { MoreHorizontal } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/utils';

import { hackerNewsCommentUrl } from './helpers';
import type { Listing, ListingAction } from './types';

export interface ListingActionsProps {
    listing: Listing;
    isFocused: boolean;
    debug: boolean;
    onAction: (verb: ListingAction) => void;
    onReparse: (force: boolean) => void;
    onSaveFixture: () => void;
    /** Extra classes for the cluster wrapper (for example to override the hover-reveal behaviour). */
    className?: string;
}

/**
 * The per-listing action cluster (open links, keep, bookmark, snooze, dismiss, more menu).
 * Its hover reveal keys off an ancestor named `group/row`.
 */
export function ListingActions({ listing, isFocused, debug, onAction, onReparse, onSaveFixture, className }: ListingActionsProps) {
    const hnCommentUrl = hackerNewsCommentUrl(listing);

    return (
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
                className,
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
    );
}
