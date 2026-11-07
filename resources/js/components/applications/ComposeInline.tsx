import { Check, Copy, Loader2 } from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';

import type { TemplateOption } from './types';

interface ComposeInlineProps {
    contactId: number;
    templates: TemplateOption[];
}

/** Renders a message template for one contact and copies it. Nothing is sent. */
export function ComposeInline({ contactId, templates }: ComposeInlineProps) {
    const [templateId, setTemplateId] = useState<number | ''>(templates[0]?.id ?? '');
    const [draft, setDraft] = useState('');
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [copied, setCopied] = useState(false);
    const idBase = `compose-${contactId}`;

    async function render() {
        if (templateId === '' || loading) {
            return;
        }
        setLoading(true);
        setError(null);
        try {
            const response = await fetch(`/contacts/${contactId}/compose`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': decodeURIComponent(
                        document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '',
                    ),
                },
                body: JSON.stringify({ template_id: templateId }),
            });
            if (!response.ok) {
                setError('Could not render that template.');
                return;
            }
            const body = (await response.json()) as { rendered: string };
            setDraft(body.rendered);
            setCopied(false);
        } catch {
            setError('Could not render that template.');
        } finally {
            setLoading(false);
        }
    }

    async function copy() {
        if (draft.trim() === '') {
            return;
        }
        try {
            await navigator.clipboard.writeText(draft);
            setCopied(true);
            window.setTimeout(() => setCopied(false), 2000);
        } catch {
            setError('Clipboard is unavailable in this browser.');
        }
    }

    if (templates.length === 0) {
        return <p className="text-base text-muted-foreground sm:text-sm">No templates yet. Add one from the Templates page.</p>;
    }

    return (
        <div className="flex flex-col gap-3">
            <div className="flex flex-col gap-2 sm:flex-row sm:items-end">
                <div className="flex min-w-0 flex-1 flex-col gap-1.5">
                    <Label htmlFor={`${idBase}-template`}>Template</Label>
                    <NativeSelect
                        id={`${idBase}-template`}
                        value={templateId}
                        onChange={(e) => setTemplateId(e.target.value === '' ? '' : Number(e.target.value))}
                        disabled={loading}
                        wrapperClassName="w-full"
                    >
                        {templates.map((template) => (
                            <option key={template.id} value={template.id}>
                                {template.name}
                            </option>
                        ))}
                    </NativeSelect>
                </div>
                <Button type="button" variant="outline" onClick={render} disabled={templateId === '' || loading}>
                    {loading ? <Loader2 className="animate-spin" aria-hidden="true" /> : null}
                    Render
                </Button>
            </div>

            {error ? <p className="text-base text-destructive sm:text-sm">{error}</p> : null}

            {draft !== '' ? (
                <div className="flex flex-col gap-1.5">
                    <div className="flex items-center justify-between gap-2">
                        <Label htmlFor={`${idBase}-draft`}>Draft</Label>
                        <Button type="button" variant="ghost" size="sm" onClick={copy}>
                            {copied ? <Check className="text-emerald-600 dark:text-emerald-400" aria-hidden="true" /> : <Copy aria-hidden="true" />}
                            {copied ? 'Copied' : 'Copy'}
                        </Button>
                    </div>
                    <textarea
                        id={`${idBase}-draft`}
                        value={draft}
                        onChange={(e) => setDraft(e.target.value)}
                        rows={10}
                        className="w-full rounded-md border border-input bg-background px-3 py-2 text-base focus-visible:outline-2 focus-visible:-outline-offset-1 focus-visible:outline-ring sm:text-sm"
                    />
                    <p className="text-sm text-pretty text-muted-foreground sm:text-xs">
                        Unknown {'{{tokens}}'} are left in place so you can fill them by hand. Nothing is sent.
                    </p>
                </div>
            ) : null}
        </div>
    );
}
