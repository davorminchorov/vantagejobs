import { Head, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import TemplateForm, { humanizeCategory } from '@/components/templates/TemplateForm';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
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

type FormState =
    | { mode: 'closed' }
    | { mode: 'create' }
    | { mode: 'edit'; template: TemplateRow };

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
            <div className="space-y-6">
                <header className="flex items-end justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight text-foreground">Templates</h1>
                        <p className="text-sm text-muted-foreground">
                            {templates.length === 0
                                ? 'No message templates yet.'
                                : `${templates.length} ${templates.length === 1 ? 'template' : 'templates'} for outreach.`}
                        </p>
                    </div>
                    <Button onClick={() => setFormState({ mode: 'create' })}>New template</Button>
                </header>

                {grouped.length === 0 ? (
                    <Card className="rounded-2xl border-border">
                        <CardContent className="px-6 py-12 text-center text-sm text-muted-foreground">
                            Create your first template to reuse cold intros, referral asks, and follow-ups.
                        </CardContent>
                    </Card>
                ) : (
                    <div className="space-y-8">
                        {grouped.map((group) => (
                            <section key={group.category} className="space-y-3">
                                <div className="flex items-center gap-2">
                                    <h2 className="font-mono text-sm font-semibold uppercase tracking-wide text-muted-foreground">
                                        {humanizeCategory(group.category)}
                                    </h2>
                                    <Badge variant="outline" className="font-mono tabular-nums">
                                        {group.rows.length}
                                    </Badge>
                                </div>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    {group.rows.map((template) => (
                                        <Card key={template.id} className="rounded-2xl border-border">
                                            <CardHeader className="flex flex-row items-start justify-between gap-2 space-y-0">
                                                <div>
                                                    <CardTitle className="text-base">{template.name}</CardTitle>
                                                    {template.updated_at ? (
                                                        <p className="font-mono text-xs tabular-nums text-muted-foreground">
                                                            Updated <RelativeDate iso={template.updated_at} />
                                                        </p>
                                                    ) : null}
                                                </div>
                                                <div className="flex shrink-0 items-center gap-2">
                                                    <button
                                                        type="button"
                                                        onClick={() => setFormState({ mode: 'edit', template })}
                                                        className="text-xs font-medium text-muted-foreground hover:text-foreground"
                                                    >
                                                        Edit
                                                    </button>
                                                    <button
                                                        type="button"
                                                        onClick={() => setPendingDelete(template)}
                                                        className="text-xs font-medium text-destructive hover:underline"
                                                    >
                                                        Delete
                                                    </button>
                                                </div>
                                            </CardHeader>
                                            <CardContent>
                                                <pre className="max-h-40 overflow-hidden whitespace-pre-wrap break-words font-mono text-xs text-muted-foreground">
                                                    {template.body}
                                                </pre>
                                            </CardContent>
                                        </Card>
                                    ))}
                                </div>
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
                            {pendingDelete
                                ? `Delete "${pendingDelete.name}"? This cannot be undone.`
                                : ''}
                        </DialogDescription>
                    </DialogHeader>
                    <div className="flex items-center justify-end gap-3">
                        <Button variant="ghost" onClick={() => setPendingDelete(null)}>
                            Cancel
                        </Button>
                        <Button variant="destructive" onClick={confirmDelete}>
                            Delete
                        </Button>
                    </div>
                </DialogContent>
            </Dialog>
        </AuthenticatedLayout>
    );
}
