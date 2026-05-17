import type { ReactNode } from 'react';

import { Link, usePage } from '@inertiajs/react';

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Separator } from '@/components/ui/separator';
import { cn } from '@/lib/utils';

interface SettingsNavItem {
    title: string;
    href: string;
    routeName: string;
}

const navItems: SettingsNavItem[] = [
    { title: 'Profile', href: '/settings/profile', routeName: 'settings.profile' },
    { title: 'Password', href: '/settings/password', routeName: 'settings.password' },
    { title: 'Two-factor authentication', href: '/settings/two-factor', routeName: 'settings.two-factor' },
    { title: 'Active sessions', href: '/settings/sessions', routeName: 'settings.sessions' },
];

interface Props {
    children: ReactNode;
    title?: string;
    description?: string;
}

export default function SettingsLayout({ children, title, description }: Props) {
    const { url } = usePage();

    return (
        <AuthenticatedLayout>
            <div className="mx-auto max-w-6xl px-4 py-8 sm:px-6 lg:px-8">
                <header className="space-y-1">
                    <h1 className="text-2xl font-semibold tracking-tight">Settings</h1>
                    <p className="text-sm text-muted-foreground">
                        Manage your account, security, and active sessions.
                    </p>
                </header>

                <Separator className="my-6" />

                <div className="flex flex-col gap-8 md:flex-row">
                    <aside className="md:w-56">
                        <nav className="flex flex-col gap-1" aria-label="Settings sections">
                            {navItems.map((item) => {
                                const active = url.startsWith(item.href);
                                return (
                                    <Link
                                        key={item.href}
                                        href={item.href}
                                        className={cn(
                                            'min-h-11 rounded-md px-3 py-2 text-sm font-medium transition-colors',
                                            active
                                                ? 'bg-secondary text-secondary-foreground'
                                                : 'text-muted-foreground hover:bg-secondary/50 hover:text-foreground',
                                        )}
                                    >
                                        {item.title}
                                    </Link>
                                );
                            })}
                        </nav>
                    </aside>

                    <section className="flex-1 space-y-6">
                        {(title || description) && (
                            <div className="space-y-1">
                                {title && <h2 className="text-lg font-semibold">{title}</h2>}
                                {description && (
                                    <p className="text-sm text-muted-foreground">{description}</p>
                                )}
                            </div>
                        )}
                        {children}
                    </section>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
