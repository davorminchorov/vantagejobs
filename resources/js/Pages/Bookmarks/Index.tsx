import { Head, Link, router } from '@inertiajs/react';

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Button } from '@/components/ui/button';
import PageHeader from '@/components/PageHeader';
import { Badge } from '@/components/ui/badge';
import { EmptyState } from '@/components/ui/empty-state';
import { RelativeDate } from '@/lib/dates';

interface BookmarkListing {
    id: number;
    parsed_company_name: string | null;
    parsed_role_title: string | null;
    parsed_remote_type: string | null;
    location_bucket: string | null;
    parsed_comp_min_usd: number | null;
    parsed_comp_max_usd: number | null;
    has_comp: boolean;
    stack_tags: string[] | null;
    url: string | null;
    posted_at: string | null;
    source: { type: string; name: string } | null;
}

interface BookmarkRow {
    id: number;
    created_at: string | null;
    expires_at: string | null;
    expires_warned_at: string | null;
    listing: BookmarkListing | null;
}

interface Paginator<T> {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    next_page_url: string | null;
    prev_page_url: string | null;
}

interface Props {
    bookmarks: Paginator<BookmarkRow>;
    sort: 'added' | 'posted' | 'expires';
    options: { sorts: string[] };
}

const SORT_LABELS: Record<string, string> = {
    added: 'Date bookmarked',
    posted: 'Posted date',
    expires: 'Expires soonest',
};

export default function BookmarksIndex({ bookmarks, sort, options }: Props) {
    function applySort(next: string) {
        router.get(
            '/bookmarks',
            { sort: next },
            {
                replace: true,
                preserveScroll: true,
                preserveState: true,
                only: ['bookmarks', 'sort'],
            },
        );
    }

    function unbookmark(id: number) {
        router.delete(`/bookmarks/${id}`, {
            preserveScroll: true,
            preserveState: true,
            only: ['bookmarks'],
        });
    }

    const warnedCount = bookmarks.data.filter((b) => b.expires_warned_at !== null).length;

    return (
        <AuthenticatedLayout>
            <Head title="Bookmarks" />
            <div className="flex flex-col gap-6">
                <PageHeader
                    title="Bookmarks"
                    subtitle={
                        <>
                            <span className="tabular-nums">{bookmarks.total}</span> bookmarked{' '}
                            {bookmarks.total === 1 ? 'listing' : 'listings'} · auto-expires after 30 days
                        </>
                    }
                    actions={
                        <div className="flex flex-wrap items-center gap-1 text-base sm:text-sm" role="group" aria-label="Sort bookmarks">
                            <span className="pr-1 text-muted-foreground">Sort:</span>
                            {options.sorts.map((s) => (
                                <button
                                    key={s}
                                    type="button"
                                    onClick={() => applySort(s)}
                                    aria-pressed={sort === s}
                                    className={
                                        'min-h-10 rounded-md px-3 py-1 focus-visible:outline-2 focus-visible:outline-ring sm:min-h-0 ' +
                                        (sort === s
                                            ? 'bg-muted font-medium text-foreground'
                                            : 'text-muted-foreground hover:bg-muted hover:text-foreground')
                                    }
                                >
                                    {SORT_LABELS[s] ?? s}
                                </button>
                            ))}
                        </div>
                    }
                />

                {warnedCount > 0 && (
                    <Badge variant="warning" className="w-fit whitespace-normal">
                        <span className="tabular-nums">{warnedCount}</span> {warnedCount === 1 ? 'bookmark expires' : 'bookmarks expire'}{' '}
                        within the next 3 days.
                    </Badge>
                )}

                {bookmarks.data.length === 0 ? (
                    <EmptyState>
                        Nothing bookmarked yet. Press <kbd className="rounded-md border border-border px-1 font-mono">B</kbd> on a listing
                        in{' '}
                        <Link href="/discover" className="font-medium text-primary hover:underline">
                            Discover
                        </Link>{' '}
                        to add it here.
                    </EmptyState>
                ) : (
                    <ul role="list" className="divide-y divide-border border-y border-border">
                        {bookmarks.data.map((row) => (
                            <li key={row.id}>
                                <BookmarkCard row={row} onRemove={() => unbookmark(row.id)} />
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </AuthenticatedLayout>
    );
}

function BookmarkCard({ row, onRemove }: { row: BookmarkRow; onRemove: () => void }) {
    const listing = row.listing;
    if (listing === null) {
        return (
            <div className="flex items-center justify-between gap-3 py-4 text-base text-muted-foreground sm:text-sm">
                Bookmarked listing no longer exists.
                <Button size="sm" variant="ghost" onClick={onRemove}>
                    Remove
                </Button>
            </div>
        );
    }

    const isWarning = row.expires_warned_at !== null;

    return (
        <div className="flex items-start justify-between gap-4 py-4">
            <div className="flex min-w-0 flex-col gap-1">
                <h3 className="text-base font-medium text-balance">{listing.parsed_role_title ?? '(role unknown)'}</h3>
                <div className="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-muted-foreground">
                    <span className="font-medium text-foreground">{listing.parsed_company_name ?? '(company unknown)'}</span>
                    {listing.source && <Badge variant="secondary">{listing.source.type}</Badge>}
                    {listing.parsed_remote_type && <span>· {listing.parsed_remote_type.replace('_', ' ')}</span>}
                    {listing.location_bucket && <span>· {listing.location_bucket}</span>}
                    {listing.has_comp && listing.parsed_comp_min_usd != null && (
                        <span className="tabular-nums">
                            · ${(listing.parsed_comp_min_usd / 1000).toFixed(0)}k
                            {listing.parsed_comp_max_usd != null && `-$${(listing.parsed_comp_max_usd / 1000).toFixed(0)}k`}
                        </span>
                    )}
                </div>
                {row.expires_at && (
                    <div className="flex items-center gap-2 text-xs text-muted-foreground">
                        {isWarning && <Badge variant="warning">Expiring soon</Badge>}
                        <span>
                            Expires <RelativeDate iso={row.expires_at} className="tabular-nums" />
                        </span>
                    </div>
                )}
                {listing.url && (
                    <a
                        href={listing.url}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="truncate font-mono text-xs text-muted-foreground hover:text-foreground hover:underline"
                    >
                        {listing.url}
                    </a>
                )}
            </div>
            <Button size="sm" variant="ghost" className="shrink-0" onClick={onRemove}>
                Remove
            </Button>
        </div>
    );
}
