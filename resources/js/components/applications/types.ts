/**
 * Props contract for /applications (App\Http\Controllers\Applications\IndexController).
 *
 * Query params: view=all|attention|upcoming (default all), stage=<status>|all
 * (default all), role=<role id> (selected application), past=1 (only with
 * view=upcoming: past events, newest first). Defaults are omitted from URLs.
 *
 * Selecting an application:
 *   router.get('/applications', { ...query, role: id }, { only: ['selected'], preserveState: true, preserveScroll: true, replace: true })
 * `selected` is a lazily evaluated closure prop, so a partial reload only
 * recomputes the panel.
 */

export type PipelineStatusValue = 'researching' | 'applied' | 'screen' | 'onsite' | 'offer' | 'rejected' | 'withdrawn';

export type ApplicationsView = 'all' | 'attention' | 'upcoming';

export interface ApplicationsFilters {
    view: ApplicationsView;
    stage: PipelineStatusValue | 'all';
    /** Only meaningful with view=upcoming. */
    past: boolean;
    /** Selected role id, or null. */
    role: number | null;
}

export interface StatusOption {
    value: PipelineStatusValue;
    label: string;
    /** Rejected and withdrawn: closed applications, never need attention. */
    terminal: boolean;
}

/**
 * Why an application needs attention:
 *  - next_step_overdue: next_action_date is before today (any non-terminal stage);
 *  - quiet: stage is applied or screen and the last activity is older than
 *    follow_up_threshold_days.
 * next_step_overdue wins when both apply.
 */
export type AttentionReason = 'next_step_overdue' | 'quiet';

export interface CompanyRef {
    id: number;
    name: string;
}

export interface EventSummary {
    id: number;
    title: string;
    /** ISO 8601, UTC. */
    starts_at: string;
    /** IANA zone the event was entered in. */
    timezone: string;
    type: string;
}

export interface ApplicationRow {
    /** Role id. */
    id: number;
    title: string;
    company: CompanyRef | null;
    status: PipelineStatusValue;
    /** ISO 8601. */
    applied_at: string | null;
    next_action: string | null;
    /** YYYY-MM-DD. */
    next_action_date: string | null;
    /** ISO 8601: latest of applied_at, a past next_action_date, the latest stage note, the latest status change. */
    last_activity_at: string | null;
    attention: AttentionReason | null;
    /** Whole days since last_activity_at, null when there is no activity. */
    days_quiet: number | null;
    /** Earliest upcoming event owned by this role. */
    next_event: EventSummary | null;
    has_offer: boolean;
}

export interface EventOwner {
    type: 'role' | 'company' | 'contact';
    id: number;
    /** "Senior Backend Engineer, Acme", "Acme", or the contact's name. */
    label: string;
    /** Set when the owner is a role in the pipeline, so the agenda can open it. */
    role_id: number | null;
    /** Link for company and contact owners (/companies/{id}, /contacts/{id}). */
    href: string | null;
}

/** CalendarEventResource shape plus a resolved owner. */
export interface AgendaEvent {
    id: number;
    owner_type: 'role' | 'company' | 'contact' | null;
    owner_id: number | null;
    owner: EventOwner | null;
    title: string;
    starts_at: string;
    timezone: string;
    type: string;
    notes: string | null;
    url: string | null;
    reminder_minutes: number | null;
}

export interface ApplicationContact {
    id: number;
    name: string;
    role_title: string | null;
    email: string | null;
    linkedin_url: string | null;
    /** ISO 8601. */
    last_touch: string | null;
    days_since_touch: number | null;
}

export interface ContactOption {
    id: number;
    name: string;
    role_title: string | null;
}

export interface StageNote {
    id: number;
    body: string;
    created_at: string | null;
}

/** RoleApplicationResource shape (unchanged from the old Pipeline/Show page). */
export interface RoleDetail {
    id: number;
    title: string;
    url: string | null;
    location: string | null;
    is_open: boolean;
    pipeline_status: PipelineStatusValue | null;
    application: {
        applied_at: string | null;
        application_source: string | null;
        referral_contact_id: number | null;
        next_action: string | null;
        next_action_date: string | null;
        general_notes: string | null;
    };
    interview_prep: string | null;
    referral_contact: (ContactOption & { email: string | null; linkedin_url: string | null }) | null;
    company: { id: number; name: string; website: string | null; careers_url: string | null; tier: string } | null;
    has_offer: boolean;
    /** OfferResource shape; see components/pipeline/OfferPanel OfferSummary. */
    offer: Record<string, unknown> | null;
    /** Keyed by stage value, newest first. */
    stage_notes: Record<string, StageNote[]>;
}

export interface SelectedApplication {
    role: RoleDetail;
    /** False when the role is not in the pipeline: panel shows read-only info and "Add to pipeline". */
    in_pipeline: boolean;
    attention: AttentionReason | null;
    /** Contacts at the role's company, never-touched first, then oldest touch first. */
    contacts: ApplicationContact[];
    /** Every tenant contact, for the referral select. */
    referral_options: ContactOption[];
    /** Events owned by this role: upcoming soonest first, then past newest first. */
    events: AgendaEvent[];
}

export interface TemplateOption {
    id: number;
    name: string;
    category: string;
}

export interface ApplicationsCounts {
    /** Non-terminal applications. */
    active: number;
    attention: number;
    /** Events from now on, any owner. */
    upcoming: number;
    /** Rejected and withdrawn. */
    closed: number;
}

export interface ApplicationsPageProps {
    filters: ApplicationsFilters;
    statuses: StatusOption[];
    application_sources: string[];
    event_types: string[];
    /**
     * Applications matching view and stage, newest activity first.
     * view=attention: only rows with attention. view=upcoming: every application
     * (the left pane shows `agenda` instead, rows are used to resolve owners).
     */
    applications: ApplicationRow[];
    counts: ApplicationsCounts;
    /**
     * view=upcoming: every event from now on (or past ones when past=1), any owner, max 200.
     * Other views: the next 5 upcoming events, for the strip above the list.
     */
    agenda: AgendaEvent[];
    selected: SelectedApplication | null;
    templates: TemplateOption[];
    follow_up_threshold_days: number;
}

/**
 * Mutation endpoints the workspace calls. Every one redirects back() (Inertia
 * reloads the current URL, keeping ?role=), except remove (see below).
 *
 *  POST   /pipeline/status                     { role_id, pipeline_status }  Moving to applied sets applied_at to now when empty.
 *  PUT    /pipeline/roles/{role}/application    all application fields + interview_prep (overwrites every column; always send all)
 *  POST   /pipeline/roles/{role}/stage-notes    { stage, body }  stage is required (the current stage)
 *  POST   /pipeline/roles/{role}/offer          offer fields (OfferPanel)
 *  GET    /pipeline/offers/comparison           JSON (OfferComparison)
 *  POST   /pipeline/roles/{role}/add            add a non-pipeline role at researching
 *  DELETE /pipeline/roles/{role}/remove         back(); the client then visits /applications without role
 *  POST   /contacts                             { name, company_id, role_title?, email?, linkedin_url?, notes? }
 *  POST   /contacts/{contact}/outreach          { channel, subject?, body?, occurred_at? }  (full log)
 *  POST   /follow-ups/{contact}/touch           quick touch, no payload
 *  POST   /contacts/{contact}/compose           { template_id } -> JSON { rendered } (fetch, not Inertia)
 *  POST   /calendar                             CalendarEventForm fields, owner_type 'role' + owner_id for an application
 *  PUT    /calendar/{event}                     same
 *  DELETE /calendar/{event}
 */
