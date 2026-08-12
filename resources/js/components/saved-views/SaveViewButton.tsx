import type { FormDataConvertible } from '@inertiajs/core';
import { router } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';

interface SaveViewButtonProps {
    readonly entity: 'companies' | 'roles' | 'discover';
    readonly filters: Record<string, FormDataConvertible>;
    readonly disabled?: boolean;
}

export default function SaveViewButton({ entity, filters, disabled = false }: SaveViewButtonProps) {
    const [open, setOpen] = useState(false);
    const [name, setName] = useState('');
    const [submitting, setSubmitting] = useState(false);

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        if (name.trim() === '') return;

        setSubmitting(true);
        router.post(
            '/saved-views',
            { name: name.trim(), entity, filters },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => {
                    setName('');
                    setOpen(false);
                },
                onFinish: () => setSubmitting(false),
            },
        );
    }

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <Button variant="outline" disabled={disabled} onClick={() => setOpen(true)} type="button">
                Save view
            </Button>
            <DialogContent className="rounded-2xl">
                <DialogHeader>
                    <DialogTitle>Save current filters as view</DialogTitle>
                    <DialogDescription>
                        Give this set of filters a name. It will appear under Views in the sidebar.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={handleSubmit} className="space-y-4">
                    <div className="space-y-1.5">
                        <label className="font-mono text-xs font-medium uppercase tracking-wide text-muted-foreground" htmlFor="saved-view-name">
                            Name
                        </label>
                        <Input
                            id="saved-view-name"
                            value={name}
                            onChange={(e) => setName(e.target.value)}
                            placeholder="e.g. Hiring A-tier"
                            autoFocus
                            maxLength={120}
                        />
                    </div>
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button type="button" variant="ghost">
                                Cancel
                            </Button>
                        </DialogClose>
                        <Button type="submit" disabled={submitting || name.trim() === ''}>
                            Save view
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
