import type { PageProps as InertiaPageProps } from '@inertiajs/core';

export interface User {
    id: number;
    name: string | null;
    email: string;
    two_factor_enabled?: boolean;
}

export interface SharedFlash {
    success: string | null;
    error: string | null;
}

export interface SharedAuth {
    user: User | null;
}

export type PageProps<T extends Record<string, unknown> = Record<string, unknown>> = InertiaPageProps &
    T & {
        auth: SharedAuth;
        flash: SharedFlash;
    };
