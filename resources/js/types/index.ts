import type { PageProps as InertiaPageProps } from '@inertiajs/core';

export interface User {
    id: number;
    tenant_id: number;
    name: string | null;
    email: string;
    timezone: string;
    two_factor_enabled?: boolean;
}

export interface SharedFlash {
    success: string | null;
    error: string | null;
}

export interface SharedAuth {
    user: User | null;
}

export interface SharedTenant {
    id: number;
    name: string;
    slug: string;
    timezone: string;
}

export interface SharedSavedView {
    id: number;
    name: string;
    entity: 'companies' | 'roles' | 'discover';
    filters: Record<string, unknown>;
    sort_order: number;
    is_default: boolean;
}

export type PageProps<T extends Record<string, unknown> = Record<string, unknown>> = InertiaPageProps &
    T & {
        auth: SharedAuth;
        tenant: SharedTenant | null;
        flash: SharedFlash;
        savedViews: SharedSavedView[];
    };
