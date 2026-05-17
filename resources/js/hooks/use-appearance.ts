import { useCallback, useEffect, useState } from 'react';

export type Appearance = 'light' | 'dark' | 'system';

const STORAGE_KEY = 'vj.appearance';

function isAppearance(value: unknown): value is Appearance {
    return value === 'light' || value === 'dark' || value === 'system';
}

function readStoredAppearance(): Appearance {
    if (typeof window === 'undefined') {
        return 'system';
    }

    const stored = window.localStorage.getItem(STORAGE_KEY);
    return isAppearance(stored) ? stored : 'system';
}

function prefersDark(): boolean {
    if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') {
        return false;
    }
    return window.matchMedia('(prefers-color-scheme: dark)').matches;
}

function applyAppearance(appearance: Appearance): void {
    if (typeof document === 'undefined') {
        return;
    }

    const shouldBeDark = appearance === 'dark' || (appearance === 'system' && prefersDark());
    document.documentElement.classList.toggle('dark', shouldBeDark);
}

export function useAppearance(): {
    appearance: Appearance;
    setAppearance: (next: Appearance) => void;
} {
    const [appearance, setAppearanceState] = useState<Appearance>(() => readStoredAppearance());

    const setAppearance = useCallback((next: Appearance) => {
        setAppearanceState(next);
        if (typeof window !== 'undefined') {
            window.localStorage.setItem(STORAGE_KEY, next);
        }
        applyAppearance(next);
    }, []);

    useEffect(() => {
        applyAppearance(appearance);
    }, [appearance]);

    useEffect(() => {
        if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') {
            return;
        }

        const media = window.matchMedia('(prefers-color-scheme: dark)');
        const handler = (): void => {
            if (appearance === 'system') {
                applyAppearance('system');
            }
        };

        media.addEventListener('change', handler);
        return () => media.removeEventListener('change', handler);
    }, [appearance]);

    return { appearance, setAppearance };
}
