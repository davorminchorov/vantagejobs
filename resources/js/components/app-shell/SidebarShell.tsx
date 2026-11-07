import { Link } from '@inertiajs/react';
import { ChevronsUpDown, Search } from 'lucide-react';
import { useState } from 'react';

import {
    initialsFor,
    isActive,
    MobileNavSheet,
    NAV_ICONS,
    NavSectionHeading,
    PRIMARY_ITEMS,
    UserMenuItems,
    Wordmark,
} from '@/components/app-shell/shared';
import { openCommandPalette } from '@/components/search/GlobalCommandPalette';
import SavedViewsNav from '@/components/saved-views/SavedViewsNav';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { type Appearance } from '@/hooks/use-appearance';
import { INSIGHTS_ITEMS, TRACKER_ITEMS, type NavLinkItem } from '@/lib/navigation';
import { cn } from '@/lib/utils';
import type { User } from '@/types';

interface ShellProps {
    user: User | null;
    currentUrl: string;
    appearance: Appearance;
    onAppearanceChange: (next: Appearance) => void;
}

function SidebarLink({ item, currentUrl }: { item: NavLinkItem; currentUrl: string }) {
    const Icon = NAV_ICONS[item.href];
    const active = isActive(currentUrl, item.href);

    return (
        <li>
            <Link
                href={item.href}
                aria-current={active ? 'page' : undefined}
                className={cn(
                    'flex items-center gap-2.5 rounded-md px-2 py-1.5 text-sm font-medium',
                    active ? 'bg-muted text-foreground' : 'text-muted-foreground hover:bg-muted/60 hover:text-foreground',
                )}
            >
                {Icon ? <Icon className="size-4 shrink-0" aria-hidden="true" /> : null}
                <span className="truncate">{item.name}</span>
            </Link>
        </li>
    );
}

function SidebarGroup({
    label,
    items,
    currentUrl,
}: {
    label?: string;
    items: ReadonlyArray<NavLinkItem>;
    currentUrl: string;
}) {
    return (
        <div>
            {label ? <NavSectionHeading>{label}</NavSectionHeading> : null}
            <ul role="list" className="flex flex-col gap-0.5">
                {items.map((item) => (
                    <SidebarLink key={item.href} item={item} currentUrl={currentUrl} />
                ))}
            </ul>
        </div>
    );
}

function SearchButton({ className }: { className?: string }) {
    return (
        <button
            type="button"
            onClick={openCommandPalette}
            className={cn(
                'text-muted-foreground ring-border hover:text-foreground flex w-full items-center gap-2 rounded-md py-1.5 pr-1.5 pl-2 text-sm ring-1 ring-inset',
                className,
            )}
        >
            <Search className="size-4 shrink-0" aria-hidden="true" />
            <span className="flex-1 text-left">Search</span>
            <kbd className="bg-muted rounded px-1.5 font-mono text-[0.625rem] font-medium">⌘K</kbd>
        </button>
    );
}

/** Option B: fixed left sidebar on desktop, compact top bar + drawer on mobile. */
export default function SidebarShell({ user, currentUrl, appearance, onAppearanceChange }: ShellProps) {
    const [mobileOpen, setMobileOpen] = useState(false);

    return (
        <>
            <div className="bg-background/90 border-border sticky top-0 z-40 flex h-14 items-center gap-2 border-b px-4 backdrop-blur sm:px-6 lg:hidden">
                <MobileNavSheet
                    user={user}
                    currentUrl={currentUrl}
                    open={mobileOpen}
                    onOpenChange={setMobileOpen}
                    appearance={appearance}
                    onAppearanceChange={onAppearanceChange}
                />
                <Wordmark />
                <div className="flex flex-1 justify-end">
                    <Button type="button" variant="ghost" size="icon" onClick={openCommandPalette} aria-label="Search">
                        <Search className="size-5" aria-hidden="true" />
                    </Button>
                </div>
            </div>

            <aside className="border-border bg-background fixed inset-y-0 left-0 z-40 flex w-64 flex-col border-r max-lg:hidden">
                <div className="flex h-14 shrink-0 items-center px-4">
                    <Wordmark />
                </div>

                <div className="px-3">
                    <SearchButton />
                </div>

                <nav className="flex flex-1 flex-col gap-6 overflow-y-auto px-3 py-5" aria-label="Primary">
                    <SidebarGroup items={PRIMARY_ITEMS} currentUrl={currentUrl} />
                    <SidebarGroup label="Tracker" items={TRACKER_ITEMS} currentUrl={currentUrl} />
                    <SidebarGroup label="Insights" items={INSIGHTS_ITEMS} currentUrl={currentUrl} />
                    <div>
                        <NavSectionHeading>Views</NavSectionHeading>
                        <SavedViewsNav variant="compact" />
                    </div>
                </nav>

                {user ? (
                    <div className="border-border border-t p-3">
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <button
                                    type="button"
                                    className="hover:bg-muted/60 flex w-full items-center gap-2.5 rounded-md p-1.5 text-left"
                                    aria-label="Open user menu"
                                >
                                    <Avatar className="size-7 shrink-0">
                                        <AvatarFallback className="text-xs">{initialsFor(user)}</AvatarFallback>
                                    </Avatar>
                                    <span className="text-foreground min-w-0 flex-1 truncate text-sm font-medium">
                                        {user.name ?? user.email}
                                    </span>
                                    <ChevronsUpDown className="text-muted-foreground size-4 shrink-0" aria-hidden="true" />
                                </button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent side="top" align="start" className="w-56">
                                <UserMenuItems user={user} appearance={appearance} onAppearanceChange={onAppearanceChange} />
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>
                ) : null}
            </aside>
        </>
    );
}
