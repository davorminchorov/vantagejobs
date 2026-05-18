import { Link, router, usePage } from '@inertiajs/react';
import { Menu } from 'lucide-react';
import { useState, type ReactNode } from 'react';

import GlobalCommandPalette from '@/components/search/GlobalCommandPalette';
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

interface NavItem {
    readonly name: string;
    readonly href: string | null;
}

const NAV_ITEMS: ReadonlyArray<NavItem> = [
    { name: 'Dashboard', href: '/dashboard' },
    { name: 'Companies', href: '/companies' },
    { name: 'Discover', href: '/discover' },
    { name: 'Bookmarks', href: '/bookmarks' },
    { name: 'Runs', href: '/admin/runs' },
    { name: 'Pipeline', href: null },
    { name: 'Settings', href: '/settings/profile' },
];

function isActive(currentUrl: string, item: NavItem): boolean {
    if (item.href === null) {
        return false;
    }
    if (item.href === '/settings/profile') {
        return currentUrl.startsWith('/settings');
    }
    return currentUrl === item.href || currentUrl.startsWith(`${item.href}/`);
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

export default function AuthenticatedLayout({ children, header }: AuthenticatedLayoutProps): ReactNode {
    const page = usePage<PageProps>();
    const { auth } = page.props;
    const user = auth.user;
    const currentUrl = page.url.split('?')[0] ?? '/';
    const [mobileOpen, setMobileOpen] = useState(false);

    return (
        <div className="bg-background text-foreground flex min-h-svh flex-col">
            <header className="bg-background/95 supports-[backdrop-filter]:bg-background/80 sticky top-0 z-40 w-full border-b backdrop-blur">
                <div className="mx-auto flex h-16 w-full max-w-7xl items-center justify-between gap-2 px-4 sm:px-6">
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
                                    {NAV_ITEMS.map((item) => {
                                        const active = isActive(currentUrl, item);
                                        const baseClasses =
                                            'focus-visible:ring-ring inline-flex min-h-11 items-center rounded-md px-3 text-sm font-medium focus-visible:outline-none focus-visible:ring-2';

                                        if (item.href === null) {
                                            return (
                                                <span
                                                    key={item.name}
                                                    className={cn(baseClasses, 'text-muted-foreground cursor-not-allowed')}
                                                    aria-disabled="true"
                                                >
                                                    {item.name}
                                                </span>
                                            );
                                        }

                                        return (
                                            <Link
                                                key={item.name}
                                                href={item.href}
                                                onClick={() => setMobileOpen(false)}
                                                aria-current={active ? 'page' : undefined}
                                                className={cn(
                                                    baseClasses,
                                                    active
                                                        ? 'bg-accent text-accent-foreground'
                                                        : 'text-foreground hover:bg-accent hover:text-accent-foreground',
                                                )}
                                            >
                                                {item.name}
                                            </Link>
                                        );
                                    })}
                                </nav>
                                <Separator className="my-4" />
                                <div className="px-2 pb-1 text-xs font-medium uppercase tracking-wide text-muted-foreground">
                                    Views
                                </div>
                                <SavedViewsNav variant="compact" onNavigate={() => setMobileOpen(false)} />
                            </SheetContent>
                        </Sheet>

                        <Link
                            href="/"
                            className="focus-visible:ring-ring rounded-md px-2 py-1 text-lg font-semibold tracking-tight focus-visible:outline-none focus-visible:ring-2"
                        >
                            VantageJobs
                        </Link>

                        <nav className="ml-6 hidden items-center gap-1 md:flex" aria-label="Primary">
                            {NAV_ITEMS.map((item) => {
                                const active = isActive(currentUrl, item);
                                const baseClasses =
                                    'focus-visible:ring-ring inline-flex h-9 items-center rounded-md px-3 text-sm font-medium focus-visible:outline-none focus-visible:ring-2';

                                if (item.href === null) {
                                    return (
                                        <span
                                            key={item.name}
                                            className={cn(baseClasses, 'text-muted-foreground cursor-not-allowed')}
                                            aria-disabled="true"
                                        >
                                            {item.name}
                                        </span>
                                    );
                                }

                                return (
                                    <Link
                                        key={item.name}
                                        href={item.href}
                                        aria-current={active ? 'page' : undefined}
                                        className={cn(
                                            baseClasses,
                                            active
                                                ? 'bg-accent text-foreground'
                                                : 'text-muted-foreground hover:text-foreground hover:bg-accent',
                                        )}
                                    >
                                        {item.name}
                                    </Link>
                                );
                            })}
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <Button
                                        variant="ghost"
                                        className="h-9 px-3 text-sm font-medium text-muted-foreground hover:text-foreground"
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
                    <div className="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6">{header}</div>
                </div>
            ) : null}

            <main className="mx-auto w-full max-w-7xl flex-1 px-4 py-6 sm:px-6">{children}</main>

            <GlobalCommandPalette />
        </div>
    );
}
