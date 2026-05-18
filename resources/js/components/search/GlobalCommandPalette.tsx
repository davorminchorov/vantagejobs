import { router } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

interface CompanyHit {
    id: number;
    name: string;
    domain: string | null;
    ats_type: string | null;
    tier: string;
    url: string;
}

interface RoleHit {
    id: number;
    title: string;
    is_open: boolean;
    company_id: number;
    company_name: string | null;
    workplace_type: string | null;
    url: string;
}

interface SearchResults {
    companies: CompanyHit[];
    roles: RoleHit[];
}

const EMPTY: SearchResults = { companies: [], roles: [] };

function humanize(value: string): string {
    return value.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

export default function GlobalCommandPalette() {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<SearchResults>(EMPTY);
    const [activeIndex, setActiveIndex] = useState(0);
    const inputRef = useRef<HTMLInputElement | null>(null);
    const abortRef = useRef<AbortController | null>(null);

    const flatItems = useMemo(() => {
        const items: { kind: 'company' | 'role'; href: string; key: string }[] = [];
        results.companies.forEach((c) => items.push({ kind: 'company', href: c.url, key: `c-${c.id}` }));
        results.roles.forEach((r) => items.push({ kind: 'role', href: r.url, key: `r-${r.id}` }));
        return items;
    }, [results]);

    const close = useCallback(() => {
        setOpen(false);
        setQuery('');
        setResults(EMPTY);
        setActiveIndex(0);
    }, []);

    // Cmd/Ctrl+K to open from anywhere.
    useEffect(() => {
        function onKey(e: KeyboardEvent) {
            if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
                e.preventDefault();
                setOpen((v) => !v);
            } else if (e.key === 'Escape' && open) {
                e.preventDefault();
                close();
            }
        }
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, [open, close]);

    // Focus the input when opening.
    useEffect(() => {
        if (open) {
            window.setTimeout(() => inputRef.current?.focus(), 0);
        }
    }, [open]);

    // Debounced search.
    useEffect(() => {
        if (!open) return;
        const trimmed = query.trim();
        if (trimmed === '' || trimmed.length < 2) {
            setResults(EMPTY);
            setActiveIndex(0);
            return;
        }

        const handle = window.setTimeout(async () => {
            abortRef.current?.abort();
            const controller = new AbortController();
            abortRef.current = controller;

            try {
                const response = await fetch(`/search?q=${encodeURIComponent(trimmed)}`, {
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json' },
                    signal: controller.signal,
                });
                if (!response.ok) return;
                const body = (await response.json()) as SearchResults;
                setResults(body);
                setActiveIndex(0);
            } catch (e) {
                if ((e as Error).name !== 'AbortError') {
                    setResults(EMPTY);
                }
            }
        }, 120);

        return () => window.clearTimeout(handle);
    }, [open, query]);

    function navigateTo(href: string) {
        close();
        router.visit(href);
    }

    function onKeyDown(e: React.KeyboardEvent<HTMLInputElement>) {
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            if (flatItems.length === 0) return;
            setActiveIndex((i) => (i + 1) % flatItems.length);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            if (flatItems.length === 0) return;
            setActiveIndex((i) => (i - 1 + flatItems.length) % flatItems.length);
        } else if (e.key === 'Enter') {
            e.preventDefault();
            const item = flatItems[activeIndex];
            if (item) {
                navigateTo(item.href);
            }
        }
    }

    if (!open) {
        return null;
    }

    return (
        <div
            role="dialog"
            aria-modal="true"
            aria-label="Global search"
            className="fixed inset-0 z-50 flex items-start justify-center bg-black/40 backdrop-blur-sm"
            onClick={close}
        >
            <div
                className="mt-[10vh] w-full max-w-xl rounded-lg border bg-popover shadow-xl"
                onClick={(e) => e.stopPropagation()}
            >
                <div className="flex items-center gap-2 border-b px-3 py-3">
                    <Search className="size-4 text-muted-foreground" aria-hidden="true" />
                    <input
                        ref={inputRef}
                        type="text"
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        onKeyDown={onKeyDown}
                        placeholder="Search companies and roles…"
                        className="w-full bg-transparent text-sm outline-none placeholder:text-muted-foreground"
                    />
                    <kbd className="hidden rounded border bg-muted px-1.5 py-0.5 text-[10px] text-muted-foreground sm:inline">
                        Esc
                    </kbd>
                </div>

                <div className="max-h-[60vh] overflow-auto py-2">
                    {query.trim().length < 2 ? (
                        <div className="px-3 py-6 text-center text-sm text-muted-foreground">
                            Type at least 2 characters.
                        </div>
                    ) : flatItems.length === 0 ? (
                        <div className="px-3 py-6 text-center text-sm text-muted-foreground">
                            No matches.
                        </div>
                    ) : (
                        <>
                            {results.companies.length > 0 ? (
                                <Group label="Companies">
                                    {results.companies.map((hit, hitIndex) => (
                                        <ResultRow
                                            key={`c-${hit.id}`}
                                            active={activeIndex === hitIndex}
                                            onSelect={() => navigateTo(hit.url)}
                                        >
                                            <div className="flex min-w-0 flex-col">
                                                <span className="truncate text-sm font-medium">{hit.name}</span>
                                                {hit.domain ? (
                                                    <span className="truncate text-xs text-muted-foreground">
                                                        {hit.domain}
                                                    </span>
                                                ) : null}
                                            </div>
                                            <div className="flex items-center gap-1.5">
                                                {hit.ats_type ? (
                                                    <span className="text-xs text-muted-foreground">
                                                        {humanize(hit.ats_type)}
                                                    </span>
                                                ) : null}
                                                <Badge variant="outline">
                                                    {hit.tier === 'graveyard' ? 'Graveyard' : `Tier ${hit.tier}`}
                                                </Badge>
                                            </div>
                                        </ResultRow>
                                    ))}
                                </Group>
                            ) : null}

                            {results.roles.length > 0 ? (
                                <Group label="Roles">
                                    {results.roles.map((hit, hitIndex) => {
                                        const flatIdx = results.companies.length + hitIndex;
                                        return (
                                            <ResultRow
                                                key={`r-${hit.id}`}
                                                active={activeIndex === flatIdx}
                                                onSelect={() => navigateTo(hit.url)}
                                            >
                                                <div className="flex min-w-0 flex-col">
                                                    <span className="truncate text-sm font-medium">{hit.title}</span>
                                                    <span className="truncate text-xs text-muted-foreground">
                                                        {hit.company_name ?? '—'}
                                                        {hit.workplace_type ? ` · ${humanize(hit.workplace_type)}` : ''}
                                                    </span>
                                                </div>
                                                {hit.is_open ? (
                                                    <Badge variant="success">Open</Badge>
                                                ) : (
                                                    <Badge variant="outline">Closed</Badge>
                                                )}
                                            </ResultRow>
                                        );
                                    })}
                                </Group>
                            ) : null}
                        </>
                    )}
                </div>

                <div className="flex items-center justify-between border-t px-3 py-2 text-[11px] text-muted-foreground">
                    <span>
                        <kbd className="rounded border bg-muted px-1">↑</kbd>{' '}
                        <kbd className="rounded border bg-muted px-1">↓</kbd> to navigate ·{' '}
                        <kbd className="rounded border bg-muted px-1">↵</kbd> to open
                    </span>
                    <span>
                        <kbd className="rounded border bg-muted px-1">⌘</kbd>
                        <kbd className="rounded border bg-muted px-1">K</kbd>
                    </span>
                </div>
            </div>
        </div>
    );
}

function Group({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div className="px-1 py-1">
            <div className="px-3 pb-1 pt-2 text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">
                {label}
            </div>
            <ul className="space-y-px">{children}</ul>
        </div>
    );
}

function ResultRow({
    active,
    onSelect,
    children,
}: {
    active: boolean;
    onSelect: () => void;
    children: React.ReactNode;
}) {
    return (
        <li>
            <button
                type="button"
                onMouseEnter={() => undefined}
                onClick={onSelect}
                className={cn(
                    'flex w-full items-center justify-between gap-3 rounded-md px-3 py-2 text-left text-sm',
                    active ? 'bg-accent text-accent-foreground' : 'hover:bg-accent/50',
                )}
            >
                {children}
            </button>
        </li>
    );
}
