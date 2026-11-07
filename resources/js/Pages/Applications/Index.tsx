import { Head, router } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/components/PageHeader';
import { AgendaList } from '@/components/applications/AgendaList';
import { ApplicationDetail } from '@/components/applications/ApplicationDetail';
import { ApplicationList } from '@/components/applications/ApplicationList';
import { DeleteEventDialog, EventFormDialog, type EventFormState } from '@/components/applications/EventDialogs';
import { FilterBar } from '@/components/applications/FilterBar';
import { UpcomingStrip } from '@/components/applications/UpcomingStrip';
import { buildQuery, isTerminal } from '@/components/applications/helpers';
import type { AgendaEvent, ApplicationsFilters, ApplicationsPageProps } from '@/components/applications/types';
import type { OwnerOption } from '@/components/calendar/CalendarEventForm';
import { useIsDesktop } from '@/components/discover/useIsDesktop';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Sheet, SheetContent, SheetDescription, SheetTitle } from '@/components/ui/sheet';

export default function ApplicationsIndexPage({
    filters,
    statuses,
    application_sources,
    event_types,
    applications,
    counts,
    agenda,
    selected,
    templates,
    follow_up_threshold_days,
}: ApplicationsPageProps) {
    const isDesktop = useIsDesktop();
    const [sheetOpen, setSheetOpen] = useState(() => !isDesktop && selected !== null);
    const [eventForm, setEventForm] = useState<EventFormState>({ mode: 'closed' });
    const [pendingDelete, setPendingDelete] = useState<AgendaEvent | null>(null);

    useEffect(() => {
        if (isDesktop) {
            setSheetOpen(false);
        }
    }, [isDesktop]);

    const selectedId = selected?.role.id ?? null;

    function visit(patch: Partial<ApplicationsFilters>, only?: string[]) {
        router.get('/applications', buildQuery(filters, patch), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            ...(only ? { only } : {}),
        });
    }

    function selectApplication(id: number) {
        visit({ role: id }, ['selected', 'filters']);
        if (!isDesktop) {
            setSheetOpen(true);
        }
    }

    function clearSelection() {
        setSheetOpen(false);
        visit({ role: null });
    }

    function closeSheet(open: boolean) {
        setSheetOpen(open);
        if (!open && filters.role !== null) {
            visit({ role: null }, ['selected', 'filters']);
        }
    }

    const ownerOptions: OwnerOption[] = useMemo(() => {
        const options = applications.map((row) => ({
            value: `role:${row.id}`,
            label: row.company ? `${row.title}, ${row.company.name}` : row.title,
        }));
        if (selected?.in_pipeline && !options.some((option) => option.value === `role:${selected.role.id}`)) {
            options.push({
                value: `role:${selected.role.id}`,
                label: selected.role.company ? `${selected.role.title}, ${selected.role.company.name}` : selected.role.title,
            });
        }
        return options;
    }, [applications, selected]);

    const totalApplications = counts.active + counts.closed;
    const stageIsClosed = filters.stage !== 'all' && isTerminal(statuses, filters.stage);
    const upcomingView = filters.view === 'upcoming';
    const hasFilters = filters.view !== 'all' || filters.stage !== 'all';

    const detail = selected ? (
        <ApplicationDetail
            key={selected.role.id}
            selected={selected}
            statuses={statuses}
            applicationSources={application_sources}
            eventTypes={event_types}
            templates={templates}
            followUpThresholdDays={follow_up_threshold_days}
            onRemoved={clearSelection}
        />
    ) : (
        <p className="py-10 text-center text-base text-pretty text-muted-foreground sm:text-sm">Select an application</p>
    );

    let leftPane: React.ReactNode;
    if (upcomingView) {
        leftPane = (
            <AgendaList
                events={agenda}
                past={filters.past}
                onTogglePast={() => visit({ past: !filters.past })}
                onAdd={() => setEventForm({ mode: 'create' })}
                onOpenApplication={selectApplication}
                onEdit={(event) => setEventForm({ mode: 'edit', event })}
                onDelete={setPendingDelete}
            />
        );
    } else if (applications.length === 0) {
        leftPane = (
            <div className="flex flex-col gap-4">
                <UpcomingStrip events={agenda} onOpenApplication={selectApplication} />
                <EmptyState
                    action={
                        hasFilters ? (
                            <Button type="button" variant="outline" size="sm" onClick={() => visit({ view: 'all', stage: 'all' })}>
                                Clear filters
                            </Button>
                        ) : undefined
                    }
                >
                    {filters.view === 'attention' && filters.stage === 'all'
                        ? 'Nothing needs attention right now.'
                        : 'No applications match these filters.'}
                </EmptyState>
            </div>
        );
    } else {
        leftPane = (
            <div className="flex flex-col gap-5">
                <UpcomingStrip events={agenda} onOpenApplication={selectApplication} />
                <ApplicationList
                    applications={applications}
                    statuses={statuses}
                    selectedId={selectedId}
                    forceClosedOpen={stageIsClosed}
                    onSelect={selectApplication}
                />
            </div>
        );
    }

    const showEmptyWorkspace = totalApplications === 0 && !upcomingView;

    return (
        <AuthenticatedLayout>
            <Head title="Applications" />
            <div className="flex flex-col gap-6">
                <PageHeader
                    title="Applications"
                    subtitle={
                        <>
                            <span className="tabular-nums">{counts.active}</span> active,{' '}
                            <span className="tabular-nums">{counts.attention}</span>{' '}
                            {counts.attention === 1 ? 'needs' : 'need'} attention
                        </>
                    }
                />

                <FilterBar filters={filters} statuses={statuses} counts={counts} onChange={(patch) => visit(patch)} />

                {showEmptyWorkspace ? (
                    <EmptyState>
                        Add a role from a company page, the Roles inbox or Discover to start tracking it here.
                    </EmptyState>
                ) : (
                    <div className="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(20rem,26rem)_minmax(0,1fr)] lg:items-start">
                        <div className="min-w-0">{leftPane}</div>

                        {isDesktop ? (
                            <div className="sticky top-6 max-h-[calc(100dvh-3rem)] overflow-y-auto rounded-xl border border-border p-6">
                                {detail}
                            </div>
                        ) : (
                            <Sheet open={sheetOpen && selected !== null} onOpenChange={closeSheet}>
                                <SheetContent side="right" className="w-full overflow-y-auto p-4 pt-14 sm:max-w-xl sm:p-6 sm:pt-14">
                                    <SheetTitle className="sr-only">{selected?.role.title ?? 'Application'}</SheetTitle>
                                    <SheetDescription className="sr-only">
                                        Stage, next steps, contacts, events and notes for the selected application.
                                    </SheetDescription>
                                    {detail}
                                </SheetContent>
                            </Sheet>
                        )}
                    </div>
                )}
            </div>

            <EventFormDialog
                state={eventForm}
                onClose={() => setEventForm({ mode: 'closed' })}
                eventTypes={event_types}
                ownerOptions={ownerOptions}
                defaultOwner={selected?.in_pipeline ? `role:${selected.role.id}` : undefined}
            />
            <DeleteEventDialog event={pendingDelete} onClose={() => setPendingDelete(null)} />
        </AuthenticatedLayout>
    );
}
