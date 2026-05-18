import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import type { FormEvent } from 'react';

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';

interface SourceRef {
    id: number;
    type: string;
    name: string;
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

const STATUS_TABS = ['new', 'kept', 'dismissed', 'snoozed', 'linked', 'auto_dismissed', 'overflow', 'duplicate'] as const;

export default function DiscoverIndex({ listings, filters, options }: Props) {
    const [focusIndex, setFocusIndex] = useState<number>(-1);
    const cardRefs = useRef<Array<HTMLDivElement | null>>([]);

    useEffect(() => {
        function onKey(e: KeyboardEvent) {
            // Ignore when typing in the search input.
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
                setFocusIndex(-1);
            }
        }

        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [focusIndex, listings.data]);

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
                        <h1 className="text-2xl font-semibold tracking-tight">Discover</h1>
                        <p className="text-sm text-muted-foreground">
                            {listings.total} {listings.total === 1 ? 'listing' : 'listings'} · J/↓ next · ↑ prev · K keep · X dismiss · S snooze · B bookmark
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
                                    onFocus={() => setFocusIndex(idx)}
                                    onAction={(verb) => actOn(listing, verb)}
                                />
                            </li>
                        ))}
                    </ul>
                )}

                <Paginator paginator={listings} />
            </div>
        </AuthenticatedLayout>
    );
}

function StatusTabs({ current, onChange }: { current: string; onChange: (s: string) => void }) {
    return (
        <div className="mb-4 flex flex-wrap gap-1 border-b">
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
                                ? 'border-b-2 border-foreground text-foreground'
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
                    className="rounded border bg-background px-2 py-1"
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
    onFocus: () => void;
    onAction: (verb: 'keep' | 'dismiss' | 'snooze' | 'bookmark') => void;
    ref?: React.Ref<HTMLDivElement>;
}

function ListingCard({ listing, isFocused, onFocus, onAction, ref }: ListingCardProps) {
    return (
        <Card
            ref={ref}
            tabIndex={0}
            onClick={onFocus}
            className={isFocused ? 'border-foreground ring-1 ring-foreground/20' : ''}
        >
            <CardContent className="space-y-2 p-4">
                <div className="flex items-baseline justify-between gap-4">
                    <div className="space-y-1">
                        <div className="flex items-baseline gap-2">
                            <h3 className="text-base font-semibold">
                                {listing.parsed_role_title ?? '(role unknown)'}
                            </h3>
                            {listing.parsed_seniority && (
                                <span className="text-xs uppercase tracking-wide text-muted-foreground">
                                    {listing.parsed_seniority}
                                </span>
                            )}
                        </div>
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
                            {listing.location_bucket && (
                                <span>· {listing.location_bucket}</span>
                            )}
                            {listing.has_comp && listing.parsed_comp_min_usd != null && (
                                <span>
                                    · ${(listing.parsed_comp_min_usd / 1000).toFixed(0)}k
                                    {listing.parsed_comp_max_usd != null && `-$${(listing.parsed_comp_max_usd / 1000).toFixed(0)}k`}
                                </span>
                            )}
                        </div>
                        {listing.stack_tags && listing.stack_tags.length > 0 && (
                            <div className="flex flex-wrap gap-1 pt-1">
                                {listing.stack_tags.slice(0, 8).map((tag) => (
                                    <span
                                        key={tag}
                                        className="rounded bg-muted px-1.5 py-0.5 text-xs text-muted-foreground"
                                    >
                                        {tag}
                                    </span>
                                ))}
                            </div>
                        )}
                    </div>
                    <div className="flex shrink-0 gap-1">
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
                        {listing.url}
                    </a>
                )}
            </CardContent>
        </Card>
    );
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
                Page {paginator.current_page} of {paginator.last_page}
            </span>
            <div className="flex gap-2">
                {paginator.prev_page_url && (
                    <Link
                        href={paginator.prev_page_url}
                        preserveScroll
                        preserveState
                        only={['listings']}
                        className="rounded border px-3 py-1 hover:bg-muted"
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
                        className="rounded border px-3 py-1 hover:bg-muted"
                    >
                        Next
                    </Link>
                )}
            </div>
        </div>
    );
}
