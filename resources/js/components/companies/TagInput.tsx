import { router } from '@inertiajs/react';
import { X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

export interface TagSummary {
    id: number;
    slug: string;
    name: string;
    usage_count?: number;
}

interface TagInputProps {
    companyId: number;
    tags: TagSummary[];
}

export default function TagInput({ companyId, tags }: TagInputProps) {
    const [query, setQuery] = useState('');
    const [suggestions, setSuggestions] = useState<TagSummary[]>([]);
    const [open, setOpen] = useState(false);
    const [busy, setBusy] = useState(false);
    const abortRef = useRef<AbortController | null>(null);
    const wrapperRef = useRef<HTMLDivElement | null>(null);

    useEffect(() => {
        if (query.trim() === '') {
            setSuggestions([]);
            return;
        }

        const handle = window.setTimeout(async () => {
            abortRef.current?.abort();
            const controller = new AbortController();
            abortRef.current = controller;

            try {
                const response = await fetch(`/tags?q=${encodeURIComponent(query.trim())}&limit=8`, {
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json' },
                    signal: controller.signal,
                });
                if (!response.ok) return;
                const body = (await response.json()) as { tags: TagSummary[] };
                setSuggestions(body.tags);
            } catch (e) {
                if ((e as Error).name !== 'AbortError') {
                    setSuggestions([]);
                }
            }
        }, 200);

        return () => window.clearTimeout(handle);
    }, [query]);

    useEffect(() => {
        function onDocClick(e: MouseEvent) {
            if (!wrapperRef.current?.contains(e.target as Node)) {
                setOpen(false);
            }
        }
        document.addEventListener('mousedown', onDocClick);
        return () => document.removeEventListener('mousedown', onDocClick);
    }, []);

    function attach(name: string) {
        const trimmed = name.trim();
        if (trimmed === '' || busy) return;

        setBusy(true);
        router.post(
            `/companies/${companyId}/tags`,
            { name: trimmed },
            {
                preserveScroll: true,
                preserveState: true,
                only: ['tags'],
                onFinish: () => {
                    setBusy(false);
                    setQuery('');
                    setSuggestions([]);
                    setOpen(false);
                },
            },
        );
    }

    function detach(tag: TagSummary) {
        if (busy) return;
        setBusy(true);
        router.delete(`/companies/${companyId}/tags/${tag.id}`, {
            preserveScroll: true,
            preserveState: true,
            only: ['tags'],
            onFinish: () => setBusy(false),
        });
    }

    const trimmed = query.trim();
    const exactMatch = suggestions.find((s) => s.name.toLowerCase() === trimmed.toLowerCase() || s.slug === trimmed);
    const showCreateItem = trimmed !== '' && !exactMatch;

    return (
        <div ref={wrapperRef} className="space-y-2">
            <div className="flex flex-wrap items-center gap-1.5">
                {tags.length === 0 ? (
                    <span className="text-xs text-muted-foreground">No tags yet.</span>
                ) : (
                    tags.map((tag) => (
                        <Badge key={tag.id} variant="secondary" className="gap-1 rounded-full py-1 pl-2 pr-1">
                            <span>{tag.name}</span>
                            <button
                                type="button"
                                onClick={() => detach(tag)}
                                aria-label={`Remove tag ${tag.name}`}
                                className="rounded-full p-0.5 transition-colors hover:bg-background/50"
                            >
                                <X className="size-3" aria-hidden="true" />
                            </button>
                        </Badge>
                    ))
                )}
            </div>

            <div className="relative">
                <Input
                    type="text"
                    value={query}
                    placeholder="Add a tag…"
                    disabled={busy}
                    onFocus={() => setOpen(true)}
                    onChange={(e) => {
                        setQuery(e.target.value);
                        setOpen(true);
                    }}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            if (trimmed !== '') attach(trimmed);
                        } else if (e.key === 'Escape') {
                            setOpen(false);
                        }
                    }}
                />

                {open && (suggestions.length > 0 || showCreateItem) ? (
                    <div className={cn(
                        'absolute z-10 mt-1 w-full overflow-hidden rounded-md border bg-popover shadow-md',
                    )}>
                        <ul className="max-h-60 overflow-auto py-1 text-sm">
                            {suggestions.map((tag) => (
                                <li key={tag.id}>
                                    <button
                                        type="button"
                                        onClick={() => attach(tag.name)}
                                        className="flex w-full items-center justify-between px-3 py-1.5 text-left hover:bg-accent hover:text-accent-foreground"
                                    >
                                        <span>{tag.name}</span>
                                        {typeof tag.usage_count === 'number' ? (
                                            <span className="font-mono text-xs tabular-nums text-muted-foreground">
                                                {tag.usage_count}
                                            </span>
                                        ) : null}
                                    </button>
                                </li>
                            ))}
                            {showCreateItem ? (
                                <li>
                                    <button
                                        type="button"
                                        onClick={() => attach(trimmed)}
                                        className="flex w-full items-center justify-between px-3 py-1.5 text-left hover:bg-accent hover:text-accent-foreground"
                                    >
                                        <span>
                                            Create <strong>“{trimmed}”</strong>
                                        </span>
                                        <span className="text-xs text-muted-foreground">new</span>
                                    </button>
                                </li>
                            ) : null}
                        </ul>
                    </div>
                ) : null}
            </div>
        </div>
    );
}
