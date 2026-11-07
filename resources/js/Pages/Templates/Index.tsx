import { Head, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/components/PageHeader';
import TemplateForm, { humanizeCategory } from '@/components/templates/TemplateForm';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { RelativeDate } from '@/lib/dates';

interface TemplateRow {
    id: number;
    name: string;
    category: string;
    body: string;
    updated_at: string | null;
}

interface Props {
    templates: TemplateRow[];
    categories: string[];
}

type FormState = { mode: 'closed' } | { mode: 'create' } | { mode: 'edit'; template: TemplateRow };

export default function TemplatesIndexPage({ templates, categories }: Props) {
    const [formState, setFormState] = useState<FormState>({ mode: 'closed' });
    const [pendingDelete, setPendingDelete] = useState<TemplateRow | null>(null);

    const grouped = useMemo(() => {
        return categories
            .map((category) => ({
                category,
                rows: templates.filter((template) => template.category === category),
            }))
            .filter((group) => group.rows.length > 0);
    }, [templates, categories]);

    function closeForm() {
        setFormState({ mode: 'closed' });
    }

    function confirmDelete() {
        if (pendingDelete === null) {
            return;
        }
        router.delete(`/templates/${pendingDelete.id}`, {
            preserveScroll: true,
            onSuccess: () => setPendingDelete(null),
        });
    }

    return (
        <AuthenticatedLayout>
            <Head title="Templates" />
            <div className="flex flex-col gap-8">
                <PageHeader
                    title="Templates"
                    subtitle={
                        templates.length === 0
                            ? 'No message templates yet.'
                            : `${templates.length} ${templates.length === 1 ? 'template' : 'templates'} for outreach.`
                    }
                    actions={
                        <Button type="button" onClick={() => setFormState({ mode: 'create' })}>
                            New template
                        </Button>
                    }
                />

                {grouped.length === 0 ? (
                    <EmptyState>Create your first template to reuse cold intros, referral asks, and follow-ups.</EmptyState>
                ) : (
                    <div className="flex flex-col gap-8">
                        {grouped.map((group) => (
                            <section key={group.category} className="flex flex-col gap-1">
                                <h2 className="text-sm font-medium text-muted-foreground">
                                    {humanizeCategory(group.category)} <span className="tabular-nums">({group.rows.length})</span>
                                </h2>
                                <ul role="list" className="divide-y divide-border border-b border-border">
                                    {group.rows.map((template) => (
                                        <li key={template.id} className="flex min-w-0 flex-col gap-2 py-4">
                                            <div className="flex items-start justify-between gap-2">
                                                <div className="min-w-0">
                                                    <h3 className="text-base font-medium text-balance">{template.name}</h3>
                                                    {template.updated_at ? (
                                                        <p className="text-sm tabular-nums text-muted-foreground">
                                                            Updated <RelativeDate iso={template.updated_at} />
                                                        </p>
                                                    ) : null}
                                                </div>
                                                <div className="flex shrink-0 items-center gap-1">
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() => setFormState({ mode: 'edit', template })}
                                                    >
                                                        Edit
                                                    </Button>
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="sm"
                                                        className="text-destructive hover:text-destructive"
                                                        onClick={() => setPendingDelete(template)}
                                                    >
                                                        Delete
                                                    </Button>
                                                </div>
                                            </div>
                                            <p className="line-clamp-3 max-w-3xl text-sm break-words whitespace-pre-wrap text-muted-foreground">
                                                {template.body}
                                            </p>
                                        </li>
                                    ))}
                                </ul>
                            </section>
                        ))}
                    </div>
                )}
            </div>

            <Dialog
                open={formState.mode !== 'closed'}
                onOpenChange={(open) => {
                    if (!open) {
                        closeForm();
                    }
                }}
            >
                <DialogContent className="max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>{formState.mode === 'edit' ? 'Edit template' : 'New template'}</DialogTitle>
                        <DialogDescription>
                            Plain markdown body. Substitution happens when you copy a template into an outreach message.
                        </DialogDescription>
                    </DialogHeader>
                    {formState.mode !== 'closed' ? (
                        <TemplateForm
                            mode={formState.mode === 'edit' ? 'edit' : 'create'}
                            categories={categories}
                            initial={
                                formState.mode === 'edit'
                                    ? {
                                          name: formState.template.name,
                                          category: formState.template.category,
                                          body: formState.template.body,
                                      }
                                    : {}
                            }
                            action={formState.mode === 'edit' ? `/templates/${formState.template.id}` : '/templates'}
                            method={formState.mode === 'edit' ? 'put' : 'post'}
                            onCancel={closeForm}
                            onSuccess={closeForm}
                        />
                    ) : null}
                </DialogContent>
            </Dialog>

            <Dialog
                open={pendingDelete !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setPendingDelete(null);
                    }
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Delete template</DialogTitle>
                        <DialogDescription>
                            {pendingDelete ? `Delete "${pendingDelete.name}"? This cannot be undone.` : ''}
                        </DialogDescription>
                    </DialogHeader>
                    <div className="flex items-center justify-end gap-3">
                        <Button type="button" variant="ghost" onClick={() => setPendingDelete(null)}>
                            Cancel
                        </Button>
                        <Button type="button" variant="destructive" onClick={confirmDelete}>
                            Delete
                        </Button>
                    </div>
                </DialogContent>
            </Dialog>
        </AuthenticatedLayout>
    );
}
