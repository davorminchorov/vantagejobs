import { Link, router, usePage } from '@inertiajs/react';
import { Menu, Search } from 'lucide-react';
import { useState, type ReactNode } from 'react';

import GlobalCommandPalette, { openCommandPalette } from '@/components/search/GlobalCommandPalette';
import SavedViewsNav from '@/components/saved-views/SavedViewsNav';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Separator } from '@/components/ui/separator';
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetTrigger } from '@/components/ui/sheet';
import { cn } from '@/lib/utils';
import type { PageProps, User } from '@/types';

interface AuthenticatedLayoutProps {
    children: ReactNode;
    title?: string;
    header?: ReactNode;
}

interface NavLinkItem {
    readonly name: string;
    readonly href: string;
}

const TRACKER_ITEMS: ReadonlyArray<NavLinkItem> = [
    { name: 'Pipeline', href: '/pipeline' },
    { name: 'Contacts', href: '/contacts' },
    { name: 'Follow-ups', href: '/follow-ups' },
    { name: 'Templates', href: '/templates' },
    { name: 'Calendar', href: '/calendar' },
];

const INSIGHTS_ITEMS: ReadonlyArray<NavLinkItem> = [
    { name: 'Hiring trends', href: '/insights/hiring' },
    { name: 'Compensation', href: '/insights/comp' },
    { name: 'Funding candidates', href: '/insights/funding-candidates' },
];

const ADMIN_ITEMS: ReadonlyArray<NavLinkItem> = [
    { name: 'Settings', href: '/settings/profile' },
    { name: 'Runs', href: '/admin/runs' },
    { name: 'Audit', href: '/admin/audit' },
    { name: 'Parser', href: '/admin/parser' },
];

function isActive(currentUrl: string, href: string): boolean {
    if (href === '/settings/profile') {
        return currentUrl.startsWith('/settings');
    }
    return currentUrl === href || currentUrl.startsWith(`${href}/`);
}

function isGroupActive(currentUrl: string, items: ReadonlyArray<NavLinkItem>): boolean {
    return items.some((item) => isActive(currentUrl, item.href));
}

function initialsFor(user: User): string {
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

function handleSignOut(): void {
    router.post('/logout');
}

function TopNavLink({ href, active, children }: { href: string; active: boolean; children: ReactNode }) {
    return (
        <Link
            href={href}
            aria-current={active ? 'page' : undefined}
            className={cn(
                'focus-visible:ring-ring inline-flex h-9 items-center whitespace-nowrap rounded-md px-3 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2',
                active
                    ? 'bg-primary text-primary-foreground'
                    : 'text-muted-foreground hover:text-foreground hover:bg-accent',
            )}
        >
            {children}
        </Link>
    );
}

function TopNavDropdown({
    label,
    items,
    active,
}: {
    label: string;
    items: ReadonlyArray<NavLinkItem>;
    active: boolean;
}) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    className={cn(
                        'h-9 px-3 text-sm font-medium whitespace-nowrap',
                        active
                            ? 'bg-primary text-primary-foreground hover:bg-primary hover:text-primary-foreground'
                            : 'text-muted-foreground hover:text-foreground',
                    )}
                >
                    {label}
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" className="w-48">
                {items.map((item) => (
                    <DropdownMenuItem key={item.href} asChild>
                        <Link href={item.href}>{item.name}</Link>
                    </DropdownMenuItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

function MobileNavLink({
    href,
    active,
    onNavigate,
    children,
}: {
    href: string;
    active: boolean;
    onNavigate: () => void;
    children: ReactNode;
}) {
    return (
        <Link
            href={href}
            onClick={onNavigate}
            aria-current={active ? 'page' : undefined}
            className={cn(
                'focus-visible:ring-ring inline-flex min-h-11 items-center whitespace-nowrap rounded-md px-3 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2',
                active
                    ? 'bg-primary text-primary-foreground'
                    : 'text-foreground hover:bg-accent hover:text-accent-foreground',
            )}
        >
            {children}
        </Link>
    );
}

function MobileNavSection({
    label,
    items,
    currentUrl,
    onNavigate,
}: {
    label: string;
    items: ReadonlyArray<NavLinkItem>;
    currentUrl: string;
    onNavigate: () => void;
}) {
    return (
        <div className="flex flex-col gap-1">
            <div className="text-muted-foreground px-3 pb-1 pt-3 font-mono text-xs font-medium uppercase tracking-wide">
                {label}
            </div>
            {items.map((item) => (
                <MobileNavLink
                    key={item.href}
                    href={item.href}
                    active={isActive(currentUrl, item.href)}
                    onNavigate={onNavigate}
                >
                    {item.name}
                </MobileNavLink>
            ))}
        </div>
    );
}

export default function AuthenticatedLayout({ children, header }: AuthenticatedLayoutProps): ReactNode {
    const page = usePage<PageProps>();
    const { auth } = page.props;
    const user = auth.user;
    const currentUrl = page.url.split('?')[0] ?? '/';
    const [mobileOpen, setMobileOpen] = useState(false);
    const closeMobile = () => setMobileOpen(false);

    return (
        <div className="bg-background text-foreground flex min-h-svh flex-col">
            <header className="bg-background/95 supports-[backdrop-filter]:bg-background/80 sticky top-0 z-40 w-full border-b backdrop-blur">
                <div className="mx-auto flex h-16 w-full max-w-[1600px] items-center justify-between gap-2 px-4 sm:px-6">
                    <div className="flex items-center gap-2">
                        <Sheet open={mobileOpen} onOpenChange={setMobileOpen}>
                            <SheetTrigger asChild>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    className="min-h-11 min-w-11 md:hidden"
                                    aria-label="Open navigation menu"
                                >
                                    <Menu className="size-5" aria-hidden="true" />
                                </Button>
                            </SheetTrigger>
                            <SheetContent side="left" className="w-72">
                                <SheetHeader>
                                    <SheetTitle>VantageJobs</SheetTitle>
                                </SheetHeader>
                                <nav className="mt-6 flex flex-col gap-1" aria-label="Mobile primary">
                                    <MobileNavLink
                                        href="/dashboard"
                                        active={isActive(currentUrl, '/dashboard')}
                                        onNavigate={closeMobile}
                                    >
                                        Dashboard
                                    </MobileNavLink>
                                    <MobileNavLink
                                        href="/discover"
                                        active={isActive(currentUrl, '/discover')}
                                        onNavigate={closeMobile}
                                    >
                                        Discover
                                    </MobileNavLink>
                                    <MobileNavLink
                                        href="/companies"
                                        active={isActive(currentUrl, '/companies')}
                                        onNavigate={closeMobile}
                                    >
                                        Companies
                                    </MobileNavLink>

                                    <MobileNavSection
                                        label="Tracker"
                                        items={TRACKER_ITEMS}
                                        currentUrl={currentUrl}
                                        onNavigate={closeMobile}
                                    />
                                    <MobileNavSection
                                        label="Insights"
                                        items={INSIGHTS_ITEMS}
                                        currentUrl={currentUrl}
                                        onNavigate={closeMobile}
                                    />

                                    <MobileNavLink
                                        href="/bookmarks"
                                        active={isActive(currentUrl, '/bookmarks')}
                                        onNavigate={closeMobile}
                                    >
                                        Bookmarks
                                    </MobileNavLink>

                                    {user?.is_admin ? (
                                        <MobileNavSection
                                            label="Admin"
                                            items={ADMIN_ITEMS}
                                            currentUrl={currentUrl}
                                            onNavigate={closeMobile}
                                        />
                                    ) : null}
                                </nav>
                                <Separator className="my-4" />
                                <div className="text-muted-foreground px-2 pb-1 font-mono text-xs font-medium uppercase tracking-wide">
                                    Views
                                </div>
                                <SavedViewsNav variant="compact" onNavigate={closeMobile} />
                            </SheetContent>
                        </Sheet>

                        <Link
                            href="/"
                            className="focus-visible:ring-ring text-foreground rounded-md px-2 py-1 text-lg font-semibold tracking-tight focus-visible:outline-none focus-visible:ring-2"
                        >
                            Vantage<span className="text-primary">Jobs</span>
                        </Link>

                        <nav className="ml-6 hidden items-center gap-1 md:flex" aria-label="Primary">
                            <TopNavLink href="/dashboard" active={isActive(currentUrl, '/dashboard')}>
                                Dashboard
                            </TopNavLink>
                            <TopNavLink href="/discover" active={isActive(currentUrl, '/discover')}>
                                Discover
                            </TopNavLink>
                            <TopNavLink href="/companies" active={isActive(currentUrl, '/companies')}>
                                Companies
                            </TopNavLink>
                            <TopNavDropdown
                                label="Tracker"
                                items={TRACKER_ITEMS}
                                active={isGroupActive(currentUrl, TRACKER_ITEMS)}
                            />
                            <TopNavDropdown
                                label="Insights"
                                items={INSIGHTS_ITEMS}
                                active={isGroupActive(currentUrl, INSIGHTS_ITEMS)}
                            />
                            <TopNavLink href="/bookmarks" active={isActive(currentUrl, '/bookmarks')}>
                                Bookmarks
                            </TopNavLink>
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <Button
                                        variant="ghost"
                                        className="h-9 px-3 text-sm font-medium whitespace-nowrap text-muted-foreground hover:text-foreground"
                                    >
                                        Views
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="start" className="w-72 p-2">
                                    <SavedViewsNav variant="compact" />
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </nav>
                    </div>

                    <div className="flex items-center gap-2">
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={openCommandPalette}
                            aria-label="Search"
                            className="min-h-11 gap-1.5 px-2.5 text-muted-foreground hover:text-foreground"
                        >
                            <Search className="size-4" aria-hidden="true" />
                            <kbd className="hidden rounded border border-border bg-muted px-1.5 py-0.5 font-mono text-[10px] font-medium text-muted-foreground sm:inline">
                                ⌘K
                            </kbd>
                        </Button>

                        {user ? (
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <Button
                                        variant="ghost"
                                        className="min-h-11 gap-2 px-2"
                                        aria-label="Open user menu"
                                    >
                                        <Avatar className="size-8">
                                            <AvatarFallback>{initialsFor(user)}</AvatarFallback>
                                        </Avatar>
                                        <span className="hidden text-sm font-medium sm:inline">
                                            {user.name ?? user.email}
                                        </span>
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end" className="w-56">
                                    <DropdownMenuLabel className="truncate">
                                        {user.name ?? user.email}
                                    </DropdownMenuLabel>
                                    {user.is_admin ? (
                                        <>
                                            <DropdownMenuSeparator />
                                            <DropdownMenuLabel className="font-mono text-xs font-normal uppercase tracking-wide text-muted-foreground">
                                                Admin
                                            </DropdownMenuLabel>
                                            {ADMIN_ITEMS.map((item) => (
                                                <DropdownMenuItem key={item.href} asChild>
                                                    <Link href={item.href}>{item.name}</Link>
                                                </DropdownMenuItem>
                                            ))}
                                        </>
                                    ) : null}
                                    <DropdownMenuSeparator />
                                    <DropdownMenuItem onSelect={handleSignOut}>Sign out</DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        ) : (
                            <Button asChild variant="ghost" className="min-h-11">
                                <Link href="/login">Sign in</Link>
                            </Button>
                        )}
                    </div>
                </div>
            </header>

            {header ? (
                <div className="border-b">
                    <div className="mx-auto w-full max-w-[1600px] px-4 py-6 sm:px-6">{header}</div>
                </div>
            ) : null}

            <main className="mx-auto w-full max-w-[1600px] flex-1 px-4 py-6 sm:px-6">{children}</main>

            <GlobalCommandPalette />
        </div>
    );
}
