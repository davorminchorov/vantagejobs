import { useSyncExternalStore } from 'react';

/** Matches Tailwind's `lg` breakpoint (64rem), where Discover shows the detail pane beside the list. */
const QUERY = '(min-width: 64rem)';

function subscribe(onChange: () => void): () => void {
    const media = window.matchMedia(QUERY);
    media.addEventListener('change', onChange);
    return () => media.removeEventListener('change', onChange);
}

export function useIsDesktop(): boolean {
    return useSyncExternalStore(
        subscribe,
        () => window.matchMedia(QUERY).matches,
        () => true,
    );
}
