import type { RefObject } from 'react';

export interface SourceRef {
    id: number;
    type: string;
    name: string;
}

export interface ParsedSlot {
    value: unknown;
    confidence: number;
    source: string;
    evidence: string | null;
}

export interface Listing {
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
    auto_dismiss_reason: string | null;
    posted_at: string | null;
    source: SourceRef | null;
    parsed: Record<string, ParsedSlot> | null;
    parser_version: number | null;
    raw: Record<string, unknown> | null;
    body_text?: string | null;
}

export interface Paginator<T> {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    next_page_url: string | null;
    prev_page_url: string | null;
}

export interface Filters {
    status: string;
    source: string | null;
    has_comp: boolean;
    remote_only: boolean;
    seniority: string | null;
    region: string | null;
    q: string | null;
}

export interface DiscoverOptions {
    sources: { value: string; label: string }[];
    statuses: string[];
    seniorities: string[];
    regions: { value: string; label: string }[];
}

export type EditableField =
    | 'company_name'
    | 'role_title'
    | 'seniority'
    | 'remote_type'
    | 'region'
    | 'comp_min_usd'
    | 'comp_max_usd'
    | 'stack_tags';

/** Values the inline correction editor can produce for any editable field. */
export type CorrectionValue = string | number | string[] | null;

export type ListingAction = 'keep' | 'dismiss' | 'snooze' | 'bookmark';

export interface EditingState {
    listingId: number;
    field: EditableField;
}

/** Props every Discover layout receives from the page. */
export interface DiscoverLayoutProps {
    listings: Listing[];
    debug: boolean;
    focusIndex: number;
    setFocusIndex: (index: number) => void;
    selectedIds: number[];
    toggleSelected: (id: number) => void;
    /** Adds every given id to the bulk selection. */
    selectMany: (ids: number[]) => void;
    editing: EditingState | null;
    setEditing: (value: EditingState | null) => void;
    actOn: (listing: Listing, verb: ListingAction) => void;
    saveCorrection: (listing: Listing, field: EditableField, value: CorrectionValue) => void;
    saveFixture: (listing: Listing) => void;
    reparse: (listing: Listing, force: boolean) => void;
    /** Attach to the element whose direct children are the listing rows (Inertia infinite scroll page tracking). */
    itemsRef: RefObject<HTMLUListElement | null>;
}
