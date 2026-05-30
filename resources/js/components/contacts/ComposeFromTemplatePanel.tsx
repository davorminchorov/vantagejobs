import { Check, Copy, Loader2 } from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';

export interface TemplateOption {
    id: number;
    name: string;
    category: string;
}

interface ComposeResponse {
    template: { id: number; name: string; category: string };
    variables: Record<string, string | null>;
    rendered: string;
}

interface ComposeFromTemplatePanelProps {
    contactId: number;
    templates: TemplateOption[];
}

export default function ComposeFromTemplatePanel({ contactId, templates }: ComposeFromTemplatePanelProps) {
    const [templateId, setTemplateId] = useState<number | ''>(templates[0]?.id ?? '');
    const [draft, setDraft] = useState('');
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [copied, setCopied] = useState(false);

    async function compose() {
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

            const body = (await response.json()) as ComposeResponse;
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

    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-base">Compose from template</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
                <div className="flex flex-col gap-2 sm:flex-row sm:items-end">
                    <div className="flex-1 space-y-1.5">
                        <Label htmlFor="compose-template">Template</Label>
                        <select
                            id="compose-template"
                            value={templateId}
                            onChange={(e) => setTemplateId(e.target.value === '' ? '' : Number(e.target.value))}
                            disabled={templates.length === 0 || loading}
                            className={cn(
                                'flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm',
                                'ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2',
                                'disabled:cursor-not-allowed disabled:opacity-50',
                            )}
                        >
                            {templates.length === 0 ? (
                                <option value="">No templates yet</option>
                            ) : (
                                templates.map((template) => (
                                    <option key={template.id} value={template.id}>
                                        {template.name}
                                    </option>
                                ))
                            )}
                        </select>
                    </div>
                    <Button type="button" onClick={compose} disabled={templateId === '' || loading}>
                        {loading ? <Loader2 className="size-4 animate-spin" aria-hidden="true" /> : null}
                        Render
                    </Button>
                </div>

                {error ? <p className="text-sm text-destructive">{error}</p> : null}

                <div className="space-y-1.5">
                    <div className="flex items-center justify-between">
                        <Label htmlFor="compose-draft">Draft</Label>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={copy}
                            disabled={draft.trim() === ''}
                            className="gap-1.5"
                        >
                            {copied ? (
                                <Check className="size-4 text-teal-600 dark:text-teal-400" aria-hidden="true" />
                            ) : (
                                <Copy className="size-4" aria-hidden="true" />
                            )}
                            {copied ? 'Copied' : 'Copy'}
                        </Button>
                    </div>
                    <textarea
                        id="compose-draft"
                        value={draft}
                        onChange={(e) => setDraft(e.target.value)}
                        rows={12}
                        placeholder="Pick a template and hit Render to draft your message…"
                        className={cn(
                            'flex w-full rounded-md border border-input bg-background px-3 py-2 text-sm',
                            'font-mono leading-relaxed ring-offset-background placeholder:text-muted-foreground',
                            'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2',
                        )}
                    />
                    <p className="text-xs text-muted-foreground">
                        Unknown {'{{tokens}}'} are left in place so you can fill them by hand. Nothing is sent.
                    </p>
                </div>
            </CardContent>
        </Card>
    );
}
