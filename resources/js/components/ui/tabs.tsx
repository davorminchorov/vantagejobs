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
                'flex max-w-full items-center gap-4 overflow-x-auto text-muted-foreground shadow-[inset_0_-1px_0_var(--border)]',
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
                'inline-flex h-10 shrink-0 items-center justify-center border-b-2 text-base font-medium whitespace-nowrap sm:text-sm',
                'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring',
                'disabled:pointer-events-none disabled:opacity-50',
                selected
                    ? 'border-primary text-foreground'
                    : 'border-transparent text-muted-foreground hover:border-border hover:text-foreground',
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
                'mt-6 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring',
                className,
            )}
            {...props}
        />
    );
}

export { Tabs, TabsContent, TabsList, TabsTrigger };
