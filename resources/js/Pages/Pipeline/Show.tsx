import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import ApplicationMetadataForm, {
    type ApplicationMetadata,
    type ContactOption,
} from '@/components/pipeline/ApplicationMetadataForm';
import InterviewPrepEditor from '@/components/pipeline/InterviewPrepEditor';
import OfferComparison from '@/components/pipeline/OfferComparison';
import OfferPanel, { type OfferSummary } from '@/components/pipeline/OfferPanel';
import StageNotesPanel, { type PipelineStage, type StageNote as PanelStageNote } from '@/components/pipeline/StageNotesPanel';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';

interface CompanySummary {
    id: number;
    name: string;
    website: string | null;
    careers_url: string | null;
    tier: string;
}

interface ReferralContact {
    id: number;
    name: string;
    role_title: string | null;
    email: string | null;
    linkedin_url: string | null;
}

interface StageNote {
    id: number;
    body: string;
    created_at: string | null;
}

interface RoleDetail {
    id: number;
    title: string;
    url: string | null;
    location: string | null;
    is_open: boolean;
    pipeline_status: string | null;
    application: ApplicationMetadata;
    interview_prep: string | null;
    referral_contact: ReferralContact | null;
    company: CompanySummary | null;
    has_offer: boolean;
    offer: OfferSummary | null;
    stage_notes: Record<string, StageNote[]>;
}

interface Props {
    role: RoleDetail;
    contacts: ContactOption[];
    options: {
        application_sources: string[];
        pipeline_statuses: string[];
    };
}

function humanize(value: string): string {
    return value.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

const STATUS_VARIANT: Record<string, 'default' | 'success' | 'warning' | 'destructive' | 'secondary' | 'outline'> = {
    researching: 'secondary',
    applied: 'default',
    screen: 'warning',
    onsite: 'warning',
    offer: 'success',
    rejected: 'destructive',
    withdrawn: 'outline',
};

export default function ShowRolePage({ role, contacts, options }: Props) {
    const [tab, setTab] = useState<'application' | 'prep' | 'notes' | 'offer'>('application');

    const stageCount = Object.values(role.stage_notes).reduce((sum, notes) => sum + notes.length, 0);

    const flatStageNotes: PanelStageNote[] = Object.entries(role.stage_notes).flatMap(([stage, notes]) =>
        notes.map((note) => ({
            id: note.id,
            stage: stage as PipelineStage,
            body: note.body,
            created_at: note.created_at ?? '',
        })),
    );

    const activeStage = (role.pipeline_status ?? 'researching') as PipelineStage;

    return (
        <AuthenticatedLayout>
            <Head title={role.title} />
            <div className="space-y-6">
                <header className="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                    <div className="space-y-1">
                        <div className="flex items-center gap-2">
                            <Link href="/pipeline" className="text-sm text-teal-700 hover:text-teal-800">
                                ← Pipeline
                            </Link>
                        </div>
                        <h1 className="text-2xl font-semibold tracking-tight">{role.title}</h1>
                        <div className="flex flex-wrap items-center gap-2">
                            {role.pipeline_status ? (
                                <Badge variant={STATUS_VARIANT[role.pipeline_status] ?? 'secondary'}>
                                    {humanize(role.pipeline_status)}
                                </Badge>
                            ) : (
                                <Badge variant="outline">Not in pipeline</Badge>
                            )}
                            {role.company ? (
                                <Link
                                    href={`/companies/${role.company.id}`}
                                    className="text-sm text-teal-700 hover:text-teal-800"
                                >
                                    {role.company.name}
                                </Link>
                            ) : null}
                            {role.location ? (
                                <span className="text-sm text-muted-foreground">{role.location}</span>
                            ) : null}
                            {role.has_offer ? <Badge variant="success">Offer recorded</Badge> : null}
                        </div>
                    </div>
                    {role.url ? (
                        <a
                            href={role.url}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="text-sm text-teal-700 hover:text-teal-800 hover:underline"
                        >
                            View posting ↗
                        </a>
                    ) : null}
                </header>

                <Tabs value={tab} onValueChange={(v) => setTab(v as typeof tab)}>
                    <TabsList>
                        <TabsTrigger value="application">Application</TabsTrigger>
                        <TabsTrigger value="prep">Interview prep</TabsTrigger>
                        <TabsTrigger value="notes">Stage notes ({stageCount})</TabsTrigger>
                        <TabsTrigger value="offer">Offer</TabsTrigger>
                    </TabsList>

                    <TabsContent value="application">
                        <Card className="rounded-2xl">
                            <CardHeader>
                                <CardTitle className="font-mono text-sm font-semibold uppercase tracking-wide text-muted-foreground">
                                    Application details
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <ApplicationMetadataForm
                                    roleId={role.id}
                                    application={role.application}
                                    interviewPrep={role.interview_prep}
                                    contacts={contacts}
                                    sources={options.application_sources}
                                />
                            </CardContent>
                        </Card>
                    </TabsContent>

                    <TabsContent value="prep">
                        <Card className="rounded-2xl">
                            <CardHeader>
                                <CardTitle className="font-mono text-sm font-semibold uppercase tracking-wide text-muted-foreground">
                                    Interview prep
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <InterviewPrepEditor
                                    roleId={role.id}
                                    interviewPrep={role.interview_prep}
                                    application={role.application}
                                />
                            </CardContent>
                        </Card>
                    </TabsContent>

                    <TabsContent value="notes">
                        <Card className="rounded-2xl">
                            <CardContent className="pt-6">
                                <StageNotesPanel roleId={role.id} notes={flatStageNotes} activeStage={activeStage} />
                            </CardContent>
                        </Card>
                    </TabsContent>

                    <TabsContent value="offer">
                        <div className="space-y-6">
                            <OfferPanel
                                roleId={role.id}
                                offer={role.offer}
                                action={`/pipeline/roles/${role.id}/offer`}
                            />
                            <OfferComparison endpoint="/pipeline/offers/comparison" />
                        </div>
                    </TabsContent>
                </Tabs>
            </div>
        </AuthenticatedLayout>
    );
}

