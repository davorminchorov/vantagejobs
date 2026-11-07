import { Link, router } from '@inertiajs/react';
import { useState } from 'react';

import ApplicationMetadataForm from '@/components/pipeline/ApplicationMetadataForm';
import InterviewPrepEditor from '@/components/pipeline/InterviewPrepEditor';
import OfferComparison from '@/components/pipeline/OfferComparison';
import OfferPanel, { type OfferSummary } from '@/components/pipeline/OfferPanel';
import StageNotesPanel, { type StageNote as PanelStageNote } from '@/components/pipeline/StageNotesPanel';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { NativeSelect } from '@/components/ui/native-select';
import { SectionLabel } from '@/components/ui/section-label';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';

import { ContactsPanel } from './ContactsPanel';
import { EventsPanel } from './EventsPanel';
import { attentionText, safeHttpUrl } from './helpers';
import type { PipelineStatusValue, SelectedApplication, StatusOption, TemplateOption } from './types';

interface ApplicationDetailProps {
    selected: SelectedApplication;
    statuses: StatusOption[];
    applicationSources: string[];
    eventTypes: string[];
    templates: TemplateOption[];
    followUpThresholdDays: number;
    /** Called after the application is removed so the page can drop `role` from the URL. */
    onRemoved: () => void;
}

type TabValue = 'overview' | 'notes' | 'prep' | 'offer';

export function ApplicationDetail({
    selected,
    statuses,
    applicationSources,
    eventTypes,
    templates,
    followUpThresholdDays,
    onRemoved,
}: ApplicationDetailProps) {
    const { role } = selected;
    const [tab, setTab] = useState<TabValue>('overview');
    const [confirmRemove, setConfirmRemove] = useState(false);
    const [busy, setBusy] = useState(false);
    const postingUrl = safeHttpUrl(role.url);

    function changeStage(value: string) {
        router.post(
            '/pipeline/status',
            { role_id: role.id, pipeline_status: value as PipelineStatusValue },
            { preserveScroll: true, preserveState: true },
        );
    }

    function addToApplications() {
        setBusy(true);
        router.post(`/pipeline/roles/${role.id}/add`, {}, {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => setBusy(false),
        });
    }

    function remove() {
        setBusy(true);
        router.delete(`/pipeline/roles/${role.id}/remove`, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                setConfirmRemove(false);
                onRemoved();
            },
            onFinish: () => setBusy(false),
        });
    }

    const header = (
        <header className="flex flex-col gap-3">
            <div className="flex flex-col gap-1">
                <h2 className="text-xl font-semibold text-balance">{role.title}</h2>
                <p className="flex flex-wrap items-center gap-x-2 gap-y-1 text-base text-muted-foreground sm:text-sm">
                    {role.company ? (
                        <Link href={`/companies/${role.company.id}`} className="text-primary hover:underline">
                            {role.company.name}
                        </Link>
                    ) : null}
                    {role.location ? <span>{role.location}</span> : null}
                    {postingUrl ? (
                        <a href={postingUrl} target="_blank" rel="noopener noreferrer" className="text-primary hover:underline">
                            View posting ↗
                        </a>
                    ) : null}
                    {!role.is_open ? <Badge variant="outline">Closed by company</Badge> : null}
                    {role.has_offer ? <Badge variant="success">Offer recorded</Badge> : null}
                </p>
            </div>
            {selected.in_pipeline ? (
                <div className="flex flex-wrap items-center gap-2">
                    <NativeSelect
                        aria-label="Stage"
                        value={role.pipeline_status ?? 'researching'}
                        onChange={(e) => changeStage(e.target.value)}
                    >
                        {statuses.map((status) => (
                            <option key={status.value} value={status.value}>
                                {status.label}
                            </option>
                        ))}
                    </NativeSelect>
                    <Button type="button" variant="ghost" size="sm" onClick={() => setConfirmRemove(true)}>
                        Remove from applications
                    </Button>
                </div>
            ) : null}
        </header>
    );

    if (!selected.in_pipeline) {
        return (
            <div className="flex flex-col gap-4">
                {header}
                <p className="text-base text-pretty text-muted-foreground sm:text-sm">
                    This role is not in your applications yet. Add it to track the stage, next steps, contacts and notes.
                </p>
                <div>
                    <Button type="button" onClick={addToApplications} disabled={busy}>
                        Add to applications
                    </Button>
                </div>
            </div>
        );
    }

    const flatStageNotes: PanelStageNote[] = Object.entries(role.stage_notes).flatMap(([stage, notes]) =>
        notes.map((note) => ({ id: note.id, stage, body: note.body, created_at: note.created_at ?? '' })),
    );
    const noteCount = flatStageNotes.length;

    return (
        <div className="flex flex-col gap-5">
            {header}

            {selected.attention ? (
                <div role="status" className="rounded-lg border border-border bg-muted/50 px-4 py-3 text-base sm:text-sm">
                    <span className="font-medium">Needs attention. </span>
                    {selected.attention === 'quiet'
                        ? `No activity for over ${followUpThresholdDays} days. Log a touch with a contact or set a next step.`
                        : `${attentionText('next_step_overdue', {
                              next_action: role.application.next_action,
                              next_action_date: role.application.next_action_date,
                              days_quiet: null,
                          })}.`}
                </div>
            ) : null}

            <Tabs value={tab} onValueChange={(value) => setTab(value as TabValue)}>
                <TabsList>
                    <TabsTrigger value="overview">Overview</TabsTrigger>
                    <TabsTrigger value="notes">Notes ({noteCount})</TabsTrigger>
                    <TabsTrigger value="prep">Prep</TabsTrigger>
                    <TabsTrigger value="offer">Offer</TabsTrigger>
                </TabsList>

                <TabsContent value="overview" className="flex flex-col gap-8">
                    <section className="flex flex-col gap-3">
                        <SectionLabel>Application</SectionLabel>
                        <ApplicationMetadataForm
                            roleId={role.id}
                            application={role.application}
                            interviewPrep={role.interview_prep}
                            contacts={selected.referral_options}
                            sources={applicationSources}
                        />
                    </section>
                    <ContactsPanel company={role.company} contacts={selected.contacts} templates={templates} />
                    <EventsPanel roleId={role.id} roleTitle={role.title} events={selected.events} eventTypes={eventTypes} />
                </TabsContent>

                <TabsContent value="notes">
                    <StageNotesPanel
                        roleId={role.id}
                        notes={flatStageNotes}
                        stages={statuses}
                        activeStage={role.pipeline_status ?? 'researching'}
                    />
                </TabsContent>

                <TabsContent value="prep">
                    <InterviewPrepEditor roleId={role.id} interviewPrep={role.interview_prep} application={role.application} />
                </TabsContent>

                <TabsContent value="offer" className="flex flex-col gap-8">
                    <OfferPanel offer={role.offer as OfferSummary | null} action={`/pipeline/roles/${role.id}/offer`} />
                    <OfferComparison endpoint="/pipeline/offers/comparison" />
                </TabsContent>
            </Tabs>

            <Dialog open={confirmRemove} onOpenChange={setConfirmRemove}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Remove from applications</DialogTitle>
                        <DialogDescription>
                            Remove &quot;{role.title}&quot; at {role.company?.name ?? 'this company'} from your applications?
                        </DialogDescription>
                    </DialogHeader>
                    <div className="flex items-center justify-end gap-3">
                        <Button type="button" variant="ghost" onClick={() => setConfirmRemove(false)}>
                            Cancel
                        </Button>
                        <Button type="button" variant="destructive" onClick={remove} disabled={busy}>
                            Remove
                        </Button>
                    </div>
                </DialogContent>
            </Dialog>
        </div>
    );
}
