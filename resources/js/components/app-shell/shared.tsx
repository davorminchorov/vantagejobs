import { Link, router } from '@inertiajs/react';
import {
    Activity,
    Banknote,
    BellRing,
    Bookmark,
    BriefcaseBusiness,
    Building2,
    CalendarDays,
    Compass,
    FileText,
    HandCoins,
    House,
    Menu,
    ScrollText,
    Settings,
    SquareKanban,
    TrendingUp,
    Users,
    Wrench,
    type LucideIcon,
} from 'lucide-react';
import { type ReactNode } from 'react';

import SavedViewsNav from '@/components/saved-views/SavedViewsNav';
import { Button } from '@/components/ui/button';
import {
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import { Separator } from '@/components/ui/separator';
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetTrigger } from '@/components/ui/sheet';
import { type Appearance } from '@/hooks/use-appearance';
import {
    ADMIN_ONLY_ITEMS,
    INSIGHTS_ITEMS,
    SETTINGS_ITEM,
    TOP_LEVEL_ITEMS,
    TRACKER_ITEMS,
    type NavLinkItem,
} from '@/lib/navigation';
import { cn } from '@/lib/utils';
import type { User } from '@/types';

export const PRIMARY_ITEMS: ReadonlyArray<NavLinkItem> = TOP_LEVEL_ITEMS;

export const NAV_ICONS: Record<string, LucideIcon> = {
    '/dashboard': House,
    '/discover': Compass,
    '/roles': BriefcaseBusiness,
    '/companies': Building2,
    '/bookmarks': Bookmark,
    '/pipeline': SquareKanban,
    '/contacts': Users,
    '/follow-ups': BellRing,
    '/templates': FileText,
    '/calendar': CalendarDays,
    '/insights/hiring': TrendingUp,
    '/insights/comp': Banknote,
    '/insights/funding-candidates': HandCoins,
    '/settings/profile': Settings,
    '/admin/runs': Activity,
    '/admin/audit': ScrollText,
    '/admin/parser': Wrench,
};

export const APPEARANCE_LABEL: Record<Appearance, string> = {
    light: 'Light',
    dark: 'Dark',
    system: 'System',
};

export function isActive(currentUrl: string, href: string): boolean {
    if (href === '/settings/profile') {
        return currentUrl.startsWith('/settings');
    }
    return currentUrl === href || currentUrl.startsWith(`${href}/`);
}

export function isGroupActive(currentUrl: string, items: ReadonlyArray<NavLinkItem>): boolean {
    return items.some((item) => isActive(currentUrl, item.href));
}

export function initialsFor(user: User): string {
    const source = user.name?.trim() || user.email;
    const parts = source.split(/\s+/).filter(Boolean);
    if (parts.length === 0) {
        return '?';
    }
    if (parts.length === 1) {
        return (parts[0] ?? '?').slice(0, 2).toUpperCase();
    }
    const first = parts[0]?.charAt(0) ?? '';
    const last = parts[parts.length - 1]?.charAt(0) ?? '';
    return (first + last).toUpperCase();
}

export function handleSignOut(): void {
    router.post('/logout');
}

export function Wordmark({ className }: { className?: string }) {
    return (
        <Link
            href="/"
            aria-label="Homepage"
            className={cn('text-foreground rounded-md text-base font-semibold tracking-tight', className)}
        >
            Vantage<span className="text-primary">Jobs</span>
        </Link>
    );
}

/** Dropdown body shared by every shell's account menu. */
export function UserMenuItems({
    user,
    appearance,
    onAppearanceChange,
}: {
    user: User;
    appearance: Appearance;
    onAppearanceChange: (next: Appearance) => void;
}) {
    return (
        <>
            <DropdownMenuLabel className="truncate">{user.name ?? user.email}</DropdownMenuLabel>
            <DropdownMenuSeparator />
            <DropdownMenuLabel className="text-muted-foreground text-xs font-normal">Appearance</DropdownMenuLabel>
            <DropdownMenuRadioGroup value={appearance} onValueChange={(value) => onAppearanceChange(value as Appearance)}>
                {(Object.keys(APPEARANCE_LABEL) as Appearance[]).map((value) => (
                    <DropdownMenuRadioItem key={value} value={value}>
                        {APPEARANCE_LABEL[value]}
                    </DropdownMenuRadioItem>
                ))}
            </DropdownMenuRadioGroup>
            <DropdownMenuSeparator />
            <DropdownMenuItem asChild>
                <Link href={SETTINGS_ITEM.href}>{SETTINGS_ITEM.name}</Link>
            </DropdownMenuItem>
            {user.is_admin ? (
                <>
                    <DropdownMenuSeparator />
                    <DropdownMenuLabel className="text-muted-foreground text-xs font-normal">Admin</DropdownMenuLabel>
                    {ADMIN_ONLY_ITEMS.map((item) => (
                        <DropdownMenuItem key={item.href} asChild>
                            <Link href={item.href}>{item.name}</Link>
                        </DropdownMenuItem>
                    ))}
                </>
            ) : null}
            <DropdownMenuSeparator />
            <DropdownMenuItem onSelect={handleSignOut}>Sign out</DropdownMenuItem>
        </>
    );
}

function SheetNavLink({
    item,
    currentUrl,
    onNavigate,
}: {
    item: NavLinkItem;
    currentUrl: string;
    onNavigate: () => void;
}) {
    const Icon = NAV_ICONS[item.href];
    const active = isActive(currentUrl, item.href);

    return (
        <li>
            <Link
                href={item.href}
                onClick={onNavigate}
                aria-current={active ? 'page' : undefined}
                className={cn(
                    'flex min-h-11 items-center gap-3 rounded-md px-3 text-base font-medium',
                    active ? 'bg-muted text-foreground' : 'text-muted-foreground hover:bg-muted hover:text-foreground',
                )}
            >
                {Icon ? <Icon className="size-4 shrink-0" aria-hidden="true" /> : null}
                {item.name}
            </Link>
        </li>
    );
}

function SheetNavGroup({
    label,
    items,
    currentUrl,
    onNavigate,
}: {
    label?: string;
    items: ReadonlyArray<NavLinkItem>;
    currentUrl: string;
    onNavigate: () => void;
}) {
    return (
        <div className="flex flex-col gap-1">
            {label ? <h3 className="text-muted-foreground px-3 pb-1 text-sm font-medium">{label}</h3> : null}
            <ul role="list" className="flex flex-col gap-0.5">
                {items.map((item) => (
                    <SheetNavLink key={item.href} item={item} currentUrl={currentUrl} onNavigate={onNavigate} />
                ))}
            </ul>
        </div>
    );
}

/** Mobile navigation drawer used by the sidebar and rail shells below `lg`. */
export function MobileNavSheet({
    user,
    currentUrl,
    open,
    onOpenChange,
    appearance,
    onAppearanceChange,
}: {
    user: User | null;
    currentUrl: string;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    appearance: Appearance;
    onAppearanceChange: (next: Appearance) => void;
}) {
    const close = () => onOpenChange(false);

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetTrigger asChild>
                <Button variant="ghost" size="icon" className="size-11 lg:hidden" aria-label="Open navigation menu">
                    <Menu className="size-5" aria-hidden="true" />
                </Button>
            </SheetTrigger>
            <SheetContent side="left" className="w-80 overflow-y-auto">
                <SheetHeader>
                    <SheetTitle>VantageJobs</SheetTitle>
                </SheetHeader>
                <nav className="mt-6 flex flex-col gap-6" aria-label="Mobile primary">
                    <SheetNavGroup items={PRIMARY_ITEMS} currentUrl={currentUrl} onNavigate={close} />
                    <SheetNavGroup label="Tracker" items={TRACKER_ITEMS} currentUrl={currentUrl} onNavigate={close} />
                    <SheetNavGroup label="Insights" items={INSIGHTS_ITEMS} currentUrl={currentUrl} onNavigate={close} />
                    <SheetNavGroup items={[SETTINGS_ITEM]} currentUrl={currentUrl} onNavigate={close} />
                    {user?.is_admin ? (
                        <SheetNavGroup label="Admin" items={ADMIN_ONLY_ITEMS} currentUrl={currentUrl} onNavigate={close} />
                    ) : null}
                    <div className="flex flex-col gap-1">
                        <h3 className="text-muted-foreground px-3 pb-1 text-sm font-medium">Appearance</h3>
                        <div className="flex gap-1 px-3" role="radiogroup" aria-label="Appearance">
                            {(Object.keys(APPEARANCE_LABEL) as Appearance[]).map((value) => (
                                <button
                                    key={value}
                                    type="button"
                                    role="radio"
                                    aria-checked={appearance === value}
                                    onClick={() => onAppearanceChange(value)}
                                    className={cn(
                                        'min-h-11 flex-1 rounded-md px-3 text-base font-medium',
                                        appearance === value
                                            ? 'bg-muted text-foreground'
                                            : 'text-muted-foreground hover:text-foreground',
                                    )}
                                >
                                    {APPEARANCE_LABEL[value]}
                                </button>
                            ))}
                        </div>
                    </div>
                </nav>
                <Separator className="my-4" />
                <h3 className="text-muted-foreground px-3 pb-1 text-sm font-medium">Views</h3>
                <SavedViewsNav variant="compact" onNavigate={close} />
            </SheetContent>
        </Sheet>
    );
}

export function NavSectionHeading({ children }: { children: ReactNode }) {
    return <h3 className="text-muted-foreground px-2 pb-1 text-xs font-medium">{children}</h3>;
}
