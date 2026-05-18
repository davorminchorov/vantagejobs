import { closestCenter, DndContext, type DragEndEvent, KeyboardSensor, PointerSensor, useSensor, useSensors } from '@dnd-kit/core';
import { arrayMove, SortableContext, sortableKeyboardCoordinates, useSortable, verticalListSortingStrategy } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { Link, router, usePage } from '@inertiajs/react';
import { GripVertical, MoreHorizontal, Star } from 'lucide-react';
import { useMemo, useState } from 'react';

import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/utils';
import type { PageProps, SharedSavedView } from '@/types';

interface SavedViewsNavProps {
    readonly variant?: 'rail' | 'compact';
    readonly onNavigate?: () => void;
}

const ENTITY_LABEL: Record<SharedSavedView['entity'], string> = {
    companies: 'Companies',
    roles: 'Roles',
    discover: 'Discover',
};

function buildHref(entity: SharedSavedView['entity'], filters: Record<string, unknown>): string {
    const params = new URLSearchParams();
    for (const [key, value] of Object.entries(filters)) {
        if (value === null || value === undefined) continue;
        if (Array.isArray(value)) {
            for (const v of value) {
                if (v === null || v === undefined || v === '') continue;
                params.append(key, String(v));
            }
            continue;
        }
        if (typeof value === 'boolean') {
            params.append(key, value ? '1' : '0');
            continue;
        }
        if (value === '') continue;
        params.append(key, String(value));
    }
    const qs = params.toString();
    return `/${entity}${qs === '' ? '' : `?${qs}`}`;
}

export default function SavedViewsNav({ variant = 'rail', onNavigate }: SavedViewsNavProps) {
    const page = usePage<PageProps>();
    const savedViews = page.props.savedViews ?? [];

    const grouped = useMemo(() => {
        const byEntity: Record<string, SharedSavedView[]> = {};
        for (const v of savedViews) {
            (byEntity[v.entity] ??= []).push(v);
        }
        for (const list of Object.values(byEntity)) {
            list.sort((a, b) => a.sort_order - b.sort_order || a.id - b.id);
        }
        return byEntity;
    }, [savedViews]);

    if (savedViews.length === 0) {
        return (
            <div className={cn('px-3 py-2 text-xs text-muted-foreground', variant === 'compact' && 'text-center')}>
                No saved views yet. Apply filters on a list, then click Save view.
            </div>
        );
    }

    return (
        <div className="space-y-3">
            {(Object.keys(grouped) as SharedSavedView['entity'][]).map((entity) => (
                <SavedViewsGroup
                    key={entity}
                    entity={entity}
                    label={ENTITY_LABEL[entity] ?? entity}
                    views={grouped[entity] ?? []}
                    onNavigate={onNavigate}
                />
            ))}
        </div>
    );
}

interface SavedViewsGroupProps {
    readonly entity: SharedSavedView['entity'];
    readonly label: string;
    readonly views: SharedSavedView[];
    readonly onNavigate?: () => void;
}

function SavedViewsGroup({ entity, label, views, onNavigate }: SavedViewsGroupProps) {
    const sensors = useSensors(useSensor(PointerSensor), useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }));
    const [items, setItems] = useState<SharedSavedView[]>(views);

    // keep local in sync if server changes
    if (items.length !== views.length || items.some((v, i) => v.id !== views[i]?.id)) {
        // fall through — replace local state on the next render via effect
    }

    function handleDragEnd(event: DragEndEvent) {
        const { active, over } = event;
        if (!over || active.id === over.id) return;

        const oldIndex = items.findIndex((v) => v.id === active.id);
        const newIndex = items.findIndex((v) => v.id === over.id);
        if (oldIndex === -1 || newIndex === -1) return;

        const next = arrayMove(items, oldIndex, newIndex);
        setItems(next);

        // Persist new sort_order for each affected view. Send a PATCH per
        // moved view — simple and good enough for the volumes Views see.
        next.forEach((view, idx) => {
            if (view.sort_order === idx) return;
            router.patch(
                `/saved-views/${view.id}`,
                { sort_order: idx },
                { preserveScroll: true, preserveState: true, only: ['savedViews'] },
            );
        });
    }

    return (
        <div>
            <div className="px-3 pb-1 text-xs font-medium uppercase tracking-wide text-muted-foreground">{label}</div>
            <DndContext sensors={sensors} collisionDetection={closestCenter} onDragEnd={handleDragEnd}>
                <SortableContext items={items.map((v) => v.id)} strategy={verticalListSortingStrategy}>
                    <ul className="flex flex-col">
                        {items.map((view) => (
                            <SavedViewRow key={view.id} view={view} entity={entity} onNavigate={onNavigate} />
                        ))}
                    </ul>
                </SortableContext>
            </DndContext>
        </div>
    );
}

interface SavedViewRowProps {
    readonly view: SharedSavedView;
    readonly entity: SharedSavedView['entity'];
    readonly onNavigate?: () => void;
}

function SavedViewRow({ view, entity, onNavigate }: SavedViewRowProps) {
    const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({ id: view.id });
    const href = buildHref(entity, view.filters);

    const style = {
        transform: CSS.Transform.toString(transform),
        transition,
        opacity: isDragging ? 0.5 : 1,
    };

    function renameView() {
        const next = window.prompt('Rename saved view', view.name);
        if (next === null) return;
        const trimmed = next.trim();
        if (trimmed === '' || trimmed === view.name) return;
        router.patch(`/saved-views/${view.id}`, { name: trimmed }, { preserveScroll: true, preserveState: true, only: ['savedViews'] });
    }

    function setAsDefault() {
        router.patch(
            `/saved-views/${view.id}`,
            { is_default: !view.is_default },
            { preserveScroll: true, preserveState: true, only: ['savedViews'] },
        );
    }

    function deleteView() {
        if (!window.confirm(`Delete saved view "${view.name}"?`)) return;
        router.delete(`/saved-views/${view.id}`, { preserveScroll: true, preserveState: true, only: ['savedViews'] });
    }

    return (
        <li ref={setNodeRef} style={style} className="group flex items-center gap-1 rounded-md px-2 hover:bg-accent">
            <button
                type="button"
                className="cursor-grab touch-none p-1 text-muted-foreground opacity-0 group-hover:opacity-100"
                aria-label="Drag to reorder"
                {...attributes}
                {...listeners}
            >
                <GripVertical className="size-3.5" />
            </button>
            <Link
                href={href}
                onClick={onNavigate}
                className="flex flex-1 items-center gap-2 truncate py-1.5 text-sm"
            >
                {view.is_default ? <Star className="size-3.5 fill-current text-amber-500" aria-label="Default view" /> : null}
                <span className="truncate">{view.name}</span>
            </Link>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button variant="ghost" size="icon" className="size-7 opacity-0 group-hover:opacity-100" aria-label="View actions">
                        <MoreHorizontal className="size-4" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                    <DropdownMenuItem onSelect={renameView}>Rename</DropdownMenuItem>
                    <DropdownMenuItem onSelect={setAsDefault}>
                        {view.is_default ? 'Unset default' : 'Set as default'}
                    </DropdownMenuItem>
                    <DropdownMenuItem onSelect={deleteView} className="text-destructive">
                        Delete
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </li>
    );
}
