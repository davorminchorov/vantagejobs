import { useEffect } from 'react';
import type { RefObject } from 'react';

/**
 * Scrolls the focused element into view when `focusIndex` changes. Elements
 * that are not rendered (a hidden layout variant, `display: none`) are skipped.
 */
export function useScrollFocusedIntoView(refs: RefObject<Array<HTMLElement | null>>, focusIndex: number): void {
    useEffect(() => {
        if (focusIndex < 0) {
            return;
        }
        const el = refs.current[focusIndex];
        if (!el || el.getClientRects().length === 0) {
            return;
        }
        const prefersReducedMotion =
            typeof window !== 'undefined' &&
            typeof window.matchMedia === 'function' &&
            window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        el.scrollIntoView({
            block: 'nearest',
            behavior: prefersReducedMotion ? 'auto' : 'smooth',
        });
        // refs is a stable ref object.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [focusIndex]);
}
