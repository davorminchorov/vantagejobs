import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import ApplicationMetadataForm, {
    type ApplicationMetadata,
    type ContactOption,
} from '@/components/pipeline/ApplicationMetadataForm';
import InterviewPrepEditor from '@/components/pipeline/InterviewPrepEditor';
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
    const [tab, setTab] = useState<'application' | 'prep' | 'notes'>('application');

    const stageCount = Object.values(role.stage_notes).reduce((sum, notes) => sum + notes.length, 0);

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
                        <StageNotes status={role.pipeline_status} grouped={role.stage_notes} />
                    </TabsContent>
                </Tabs>
            </div>
        </AuthenticatedLayout>
    );
}

function StageNotes({
    status,
    grouped,
}: {
    status: string | null;
    grouped: Record<string, StageNote[]>;
}) {
    const stages = Object.keys(grouped);

    if (stages.length === 0) {
        return (
            <Card className="rounded-2xl">
                <CardContent className="px-6 py-12 text-center text-sm text-muted-foreground">
                    No stage notes yet. They&rsquo;re captured as you move this role through the pipeline.
                </CardContent>
            </Card>
        );
    }

    return (
        <div className="space-y-4">
            {stages.map((stage) => (
                <Card key={stage} className="rounded-2xl">
                    <CardHeader className="flex flex-row items-center justify-between space-y-0">
                        <CardTitle className="font-mono text-sm font-semibold uppercase tracking-wide text-muted-foreground">
                            {humanize(stage)}
                        </CardTitle>
                        {stage === status ? <Badge variant="secondary">Current stage</Badge> : null}
                    </CardHeader>
                    <CardContent className="p-0">
                        <ol className="divide-y">
                            {grouped[stage].map((note) => (
                                <li key={note.id} className="flex flex-col gap-1 px-6 py-4">
                                    <p className="whitespace-pre-wrap text-sm">{note.body}</p>
                                    <span className="font-mono text-xs tabular-nums text-muted-foreground">
                                        {note.created_at ? new Date(note.created_at).toLocaleString() : ''}
                                    </span>
                                </li>
                            ))}
                        </ol>
                    </CardContent>
                </Card>
            ))}
        </div>
    );
}
