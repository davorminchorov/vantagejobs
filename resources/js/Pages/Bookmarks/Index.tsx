import { Head, Link, router } from '@inertiajs/react';

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';

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
        router.get('/bookmarks', { sort: next }, {
            replace: true,
            preserveScroll: true,
            preserveState: true,
            only: ['bookmarks', 'sort'],
        });
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
            <div className="mx-auto max-w-6xl px-4 py-8 sm:px-6 lg:px-8">
                <header className="mb-6 flex items-end justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">Bookmarks</h1>
                        <p className="text-sm text-muted-foreground">
                            {bookmarks.total} bookmarked {bookmarks.total === 1 ? 'listing' : 'listings'} · auto-expires after 30 days
                        </p>
                    </div>
                    <div className="flex gap-2 text-sm">
                        <span className="text-muted-foreground">Sort:</span>
                        {options.sorts.map((s) => (
                            <button
                                key={s}
                                type="button"
                                onClick={() => applySort(s)}
                                className={
                                    'rounded px-2 py-1 ' +
                                    (sort === s
                                        ? 'bg-foreground text-background'
                                        : 'text-muted-foreground hover:text-foreground')
                                }
                            >
                                {SORT_LABELS[s] ?? s}
                            </button>
                        ))}
                    </div>
                </header>

                {warnedCount > 0 && (
                    <div className="mb-6 rounded-md border border-dashed border-amber-500/40 bg-amber-500/10 px-4 py-3 text-sm">
                        {warnedCount} {warnedCount === 1 ? 'bookmark expires' : 'bookmarks expire'} within the next 3 days.
                    </div>
                )}

                {bookmarks.data.length === 0 ? (
                    <Card>
                        <CardContent className="py-12 text-center text-sm text-muted-foreground">
                            Nothing bookmarked yet. Press <kbd className="rounded border px-1">B</kbd> on a listing in{' '}
                            <Link href="/discover" className="underline">
                                Discover
                            </Link>{' '}
                            to add it here.
                        </CardContent>
                    </Card>
                ) : (
                    <ul className="space-y-3">
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
            <Card>
                <CardContent className="py-4 text-sm text-muted-foreground">
                    Bookmarked listing no longer exists.
                    <Button size="sm" variant="ghost" onClick={onRemove} className="ml-3">
                        Remove
                    </Button>
                </CardContent>
            </Card>
        );
    }

    const expiresAt = row.expires_at ? new Date(row.expires_at) : null;
    const isWarning = row.expires_warned_at !== null;

    return (
        <Card className={isWarning ? 'border-amber-500/40' : ''}>
            <CardContent className="space-y-2 p-4">
                <div className="flex items-baseline justify-between gap-4">
                    <div className="space-y-1">
                        <h3 className="text-base font-semibold">
                            {listing.parsed_role_title ?? '(role unknown)'}
                        </h3>
                        <div className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                            <span className="font-medium text-foreground">
                                {listing.parsed_company_name ?? '(company unknown)'}
                            </span>
                            {listing.source && (
                                <span className="rounded bg-secondary px-2 py-0.5 text-xs uppercase">
                                    {listing.source.type}
                                </span>
                            )}
                            {listing.parsed_remote_type && (
                                <span>· {listing.parsed_remote_type.replace('_', ' ')}</span>
                            )}
                            {listing.location_bucket && <span>· {listing.location_bucket}</span>}
                            {listing.has_comp && listing.parsed_comp_min_usd != null && (
                                <span>
                                    · ${(listing.parsed_comp_min_usd / 1000).toFixed(0)}k
                                    {listing.parsed_comp_max_usd != null &&
                                        `-$${(listing.parsed_comp_max_usd / 1000).toFixed(0)}k`}
                                </span>
                            )}
                        </div>
                        {expiresAt && (
                            <p className="text-xs text-muted-foreground">
                                Expires {expiresAt.toLocaleDateString()}
                            </p>
                        )}
                    </div>
                    <Button size="sm" variant="ghost" onClick={onRemove}>
                        Remove
                    </Button>
                </div>
                {listing.url && (
                    <a
                        href={listing.url}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="text-xs text-muted-foreground hover:text-foreground"
                    >
                        {listing.url}
                    </a>
                )}
            </CardContent>
        </Card>
    );
}
