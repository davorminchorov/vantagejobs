import { useCallback, useEffect, useRef, useState } from 'react';

/**
 * Optional per-row action handlers keyed by the pressed key. The handler
 * receives the zero-based index of the currently focused row. Returning is
 * ignored; bind side effects (keep / dismiss / open) inside the callback.
 */
export type ListKeyboardActions = Record<string, (index: number) => void>;

interface UseListKeyboardNavOptions {
    /** Number of rows currently rendered. */
    itemCount: number;
    /**
     * Action map. Keys are matched case-insensitively against `event.key`.
     * `Enter` is commonly bound to the primary action.
     */
    actions?: ListKeyboardActions;
    /** Disable the global listener (e.g. while a modal owns the keyboard). */
    enabled?: boolean;
}

interface UseListKeyboardNavResult {
    /** Index of the row with roving focus, or -1 when nothing is focused. */
    focusIndex: number;
    setFocusIndex: (next: number | ((prev: number) => number)) => void;
    /**
     * Ref callback to attach to each row element. Pass the row's index so the
     * hook can scroll the active row into view and apply roving focus.
     */
    registerItem: (index: number) => (el: HTMLElement | null) => void;
    /** True when the given index currently holds roving focus. */
    isFocused: (index: number) => boolean;
}

/**
 * Roving keyboard navigation for vertical lists and tables.
 *
 * `j` / `ArrowDown` move down, `k` / `ArrowUp` move up, `Escape` clears the
 * focus. Any extra single-key actions are supplied via `actions`. Keystrokes
 * are ignored while focus is inside an input, textarea, select, or
 * contenteditable element so inline editors keep working.
 *
 * The hook keeps `prefers-reduced-motion` honest: scrolling uses `smooth`
 * behaviour only when the user has not asked to reduce motion.
 */
export function useListKeyboardNav({
    itemCount,
    actions,
    enabled = true,
}: UseListKeyboardNavOptions): UseListKeyboardNavResult {
    const [focusIndex, setFocusIndexState] = useState<number>(-1);
    const itemRefs = useRef<Array<HTMLElement | null>>([]);

    const setFocusIndex = useCallback((next: number | ((prev: number) => number)) => {
        setFocusIndexState((prev) => {
            const resolved = typeof next === 'function' ? next(prev) : next;
            if (itemCount === 0) {
                return -1;
            }
            return Math.max(-1, Math.min(itemCount - 1, resolved));
        });
    }, [itemCount]);

    const registerItem = useCallback(
        (index: number) => (el: HTMLElement | null) => {
            itemRefs.current[index] = el;
        },
        [],
    );

    const isFocused = useCallback((index: number) => index === focusIndex, [focusIndex]);

    useEffect(() => {
        if (!enabled) {
            return;
        }

        function isTypingTarget(target: EventTarget | null): boolean {
            const el = target as HTMLElement | null;
            if (!el) {
                return false;
            }
            const tag = el.tagName;
            return (
                tag === 'INPUT' ||
                tag === 'TEXTAREA' ||
                tag === 'SELECT' ||
                el.isContentEditable
            );
        }

        function onKey(event: KeyboardEvent): void {
            if (isTypingTarget(event.target)) {
                return;
            }
            if (itemCount === 0) {
                return;
            }

            if (event.key === 'j' || event.key === 'ArrowDown') {
                event.preventDefault();
                setFocusIndex((i) => Math.min(itemCount - 1, i + 1));
                return;
            }
            if (event.key === 'k' || event.key === 'ArrowUp') {
                event.preventDefault();
                setFocusIndex((i) => (i < 0 ? 0 : Math.max(0, i - 1)));
                return;
            }
            if (event.key === 'Escape') {
                setFocusIndex(-1);
                return;
            }

            if (focusIndex < 0 || !actions) {
                return;
            }

            const handler = actions[event.key] ?? actions[event.key.toLowerCase()];
            if (handler) {
                event.preventDefault();
                handler(focusIndex);
            }
        }

        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [enabled, itemCount, focusIndex, actions, setFocusIndex]);

    useEffect(() => {
        if (focusIndex < 0) {
            return;
        }
        const el = itemRefs.current[focusIndex];
        if (!el) {
            return;
        }
        const prefersReducedMotion =
            typeof window !== 'undefined' &&
            typeof window.matchMedia === 'function' &&
            window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        el.scrollIntoView({ block: 'nearest', behavior: prefersReducedMotion ? 'auto' : 'smooth' });
        el.focus({ preventScroll: true });
    }, [focusIndex]);

    return { focusIndex, setFocusIndex, registerItem, isFocused };
}
