import { useEffect, useState } from 'react';

type DescriptionState =
    | { status: 'idle' | 'loading' }
    | { status: 'loaded'; text: string | null }
    | { status: 'error' };

/** Descriptions already fetched this page visit, keyed by role id. */
const cache = new Map<number, string | null>();

/**
 * Loads a role's plain-text description from `/roles/{id}/description` the
 * first time `enabled` turns true. Descriptions can be large, so the inbox
 * only fetches the ones you expand.
 */
export function useRoleDescription(roleId: number, enabled: boolean): DescriptionState {
    const [state, setState] = useState<DescriptionState>(() =>
        cache.has(roleId) ? { status: 'loaded', text: cache.get(roleId) ?? null } : { status: 'idle' },
    );

    useEffect(() => {
        if (!enabled || state.status === 'loaded' || state.status === 'loading') {
            return;
        }
        if (cache.has(roleId)) {
            setState({ status: 'loaded', text: cache.get(roleId) ?? null });
            return;
        }

        const controller = new AbortController();
        setState({ status: 'loading' });
        fetch(`/roles/${roleId}/description`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal: controller.signal,
        })
            .then((response) => {
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }
                return response.json() as Promise<{ body_text: string | null }>;
            })
            .then((payload) => {
                cache.set(roleId, payload.body_text);
                setState({ status: 'loaded', text: payload.body_text });
            })
            .catch((error: unknown) => {
                if (error instanceof DOMException && error.name === 'AbortError') {
                    return;
                }
                setState({ status: 'error' });
            });

        return () => controller.abort();
        // state is read only to skip refetching; re-running on it would loop.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [roleId, enabled]);

    return state;
}
