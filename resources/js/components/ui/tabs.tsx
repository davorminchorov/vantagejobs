import * as React from 'react';

import { cn } from '@/lib/utils';

interface TabsContextValue {
    value: string;
    setValue: (value: string) => void;
    baseId: string;
}

const TabsContext = React.createContext<TabsContextValue | null>(null);

function useTabs(): TabsContextValue {
    const ctx = React.useContext(TabsContext);
    if (!ctx) {
        throw new Error('Tabs subcomponents must be used inside <Tabs>.');
    }
    return ctx;
}

interface TabsProps {
    value: string;
    onValueChange: (value: string) => void;
    children: React.ReactNode;
    className?: string;
}

function Tabs({ value, onValueChange, children, className }: TabsProps) {
    const baseId = React.useId();
    const ctx = React.useMemo(
        () => ({ value, setValue: onValueChange, baseId }),
        [value, onValueChange, baseId],
    );

    return (
        <TabsContext.Provider value={ctx}>
            <div className={className}>{children}</div>
        </TabsContext.Provider>
    );
}

function TabsList({ className, ...props }: React.HTMLAttributes<HTMLDivElement>) {
    return (
        <div
            role="tablist"
            className={cn(
                'inline-flex items-center justify-center gap-1 rounded-md bg-muted p-1 text-muted-foreground',
                className,
            )}
            onKeyDown={(e) => {
                const target = e.target as HTMLElement;
                if (target.getAttribute('role') !== 'tab') return;
                const tabs = Array.from(
                    e.currentTarget.querySelectorAll<HTMLButtonElement>('[role="tab"]'),
                );
                const index = tabs.indexOf(target as HTMLButtonElement);
                if (index < 0) return;

                let next = index;
                if (e.key === 'ArrowRight') next = (index + 1) % tabs.length;
                else if (e.key === 'ArrowLeft') next = (index - 1 + tabs.length) % tabs.length;
                else if (e.key === 'Home') next = 0;
                else if (e.key === 'End') next = tabs.length - 1;
                else return;

                e.preventDefault();
                tabs[next]?.focus();
                tabs[next]?.click();
            }}
            {...props}
        />
    );
}

interface TabsTriggerProps extends React.ButtonHTMLAttributes<HTMLButtonElement> {
    value: string;
}

function TabsTrigger({ value, className, ...props }: TabsTriggerProps) {
    const { value: active, setValue, baseId } = useTabs();
    const selected = active === value;

    return (
        <button
            type="button"
            role="tab"
            id={`${baseId}-tab-${value}`}
            aria-selected={selected}
            aria-controls={`${baseId}-panel-${value}`}
            tabIndex={selected ? 0 : -1}
            data-state={selected ? 'active' : 'inactive'}
            onClick={() => setValue(value)}
            className={cn(
                'inline-flex h-9 items-center justify-center rounded-sm px-3 text-sm font-medium transition-colors',
                'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                'disabled:pointer-events-none disabled:opacity-50',
                selected
                    ? 'bg-background text-foreground shadow-sm'
                    : 'text-muted-foreground hover:text-foreground',
                className,
            )}
            {...props}
        />
    );
}

interface TabsContentProps extends React.HTMLAttributes<HTMLDivElement> {
    value: string;
}

function TabsContent({ value, className, ...props }: TabsContentProps) {
    const { value: active, baseId } = useTabs();
    const selected = active === value;
    if (!selected) return null;

    return (
        <div
            role="tabpanel"
            id={`${baseId}-panel-${value}`}
            aria-labelledby={`${baseId}-tab-${value}`}
            tabIndex={0}
            className={cn(
                'mt-4 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                className,
            )}
            {...props}
        />
    );
}

export { Tabs, TabsContent, TabsList, TabsTrigger };
