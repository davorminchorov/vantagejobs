export type SourceHealthStatus = 'green' | 'yellow' | 'red';
export type SourceHealthKind = 'aggregator' | 'ats';

export interface SourceHealthRow {
    kind: SourceHealthKind;
    value: string;
    source_id: number | null;
    company_id: number | null;
    key: string | null;
    name: string;
    status: SourceHealthStatus;
    reasons: string[];
    consecutive_failures: number;
    last_run_at: string | null;
    last_error: string | null;
    confidence_p50: number | null;
    confidence_p50_prev: number | null;
    confidence_drop_pct: number | null;
}

export interface SourceHealthSummary {
    status: SourceHealthStatus;
    counts: { green: number; yellow: number; red: number };
    total: number;
    sources: SourceHealthRow[];
}

export interface WatchListCompany {
    id: number;
    name: string;
    tier: 'A' | 'B' | 'C' | 'graveyard';
    status: string;
    open_roles: number;
    last_checked_at: string | null;
}

export interface AutoDismissRule {
    display_name: string;
    normalized_company_key: string;
    dismiss_count: number;
}

export interface AttentionRow {
    role_id: number;
    title: string;
    company_name: string | null;
    reason: 'next_step_overdue' | 'quiet';
    /** Plain wording, e.g. "Next step overdue" or "No activity for 9 days". */
    reason_label: string;
    /** Relative path to /applications?role=ID. */
    url: string;
}

export interface ApplicationsAttention {
    count: number;
    top: AttentionRow[];
}

export interface PipelineStage {
    status: string;
    label: string;
    terminal: boolean;
    count: number;
}

export interface PipelineSummary {
    total_active: number;
    stages: PipelineStage[];
}

export interface DashboardProps {
    companies: WatchListCompany[];
    companies_count: number;
    active_auto_dismiss_rules: AutoDismissRule[];
    source_health?: SourceHealthSummary;
    applications_attention: ApplicationsAttention;
    pipeline_summary: PipelineSummary;
    discover_new_count: number;
}

export const tierVariant: Record<string, 'success' | 'warning' | 'secondary' | 'destructive'> = {
    A: 'success',
    B: 'warning',
    C: 'secondary',
    graveyard: 'destructive',
};

export function pluralize(count: number, singular: string, plural: string): string {
    return `${count} ${count === 1 ? singular : plural}`;
}
