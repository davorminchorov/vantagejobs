import type { ReactNode } from 'react';

import { Link, usePage } from '@inertiajs/react';

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/components/PageHeader';
import { cn } from '@/lib/utils';

interface SettingsNavItem {
    title: string;
    href: string;
    routeName: string;
}

interface SettingsNavSection {
    label: string;
    items: SettingsNavItem[];
}

const navSections: SettingsNavSection[] = [
    {
        label: 'Profile',
        items: [
            { title: 'Profile', href: '/settings/profile', routeName: 'settings.profile' },
            { title: 'Calendar feed', href: '/settings/calendar', routeName: 'settings.calendar' },
        ],
    },
    {
        label: 'Notifications',
        items: [
            { title: 'Notifications', href: '/settings/notifications', routeName: 'settings.notifications' },
        ],
    },
    {
        label: 'Sources & triage',
        items: [
            { title: 'Triage', href: '/settings/triage', routeName: 'settings.triage' },
            { title: 'Sources', href: '/discover/sources', routeName: 'discover.sources.index' },
        ],
    },
    {
        label: 'Account',
        items: [
            { title: 'Password', href: '/settings/password', routeName: 'settings.password' },
            { title: 'Two-factor authentication', href: '/settings/two-factor', routeName: 'settings.two-factor' },
            { title: 'Active sessions', href: '/settings/sessions', routeName: 'settings.sessions' },
        ],
    },
    {
        label: 'Data',
        items: [{ title: 'Export & data', href: '/settings/data', routeName: 'settings.data' }],
    },
];

interface Props {
    children: ReactNode;
    title?: string;
    description?: string;
}

interface SettingsSectionProps {
    title: string;
    description?: ReactNode;
    children: ReactNode;
}

/**
 * Two-column settings section: heading and description on the left, fields on
 * the right (lg+). Sections are separated by a top border.
 */
export function SettingsSection({ title, description, children }: SettingsSectionProps) {
    return (
        <section className="grid grid-cols-1 gap-6 border-t border-border py-8 first:border-t-0 first:pt-0 lg:grid-cols-[1fr_2fr]">
            <div className="flex flex-col gap-1">
                <h3 className="text-base font-semibold text-balance sm:text-sm">{title}</h3>
                {description ? (
                    <p className="text-base text-pretty text-muted-foreground sm:text-sm">{description}</p>
                ) : null}
            </div>
            <div className="min-w-0 max-w-lg">{children}</div>
        </section>
    );
}

export default function SettingsLayout({ children }: Props) {
    const { url } = usePage();

    return (
        <AuthenticatedLayout>
            <div className="flex flex-col gap-6">
                <PageHeader title="Settings" subtitle="Manage your account, security, and active sessions." />

                <div className="flex flex-col gap-6 lg:flex-row lg:gap-10">
                    <nav
                        className="-mx-4 flex gap-1 overflow-x-auto border-b border-border px-4 pb-2 sm:-mx-6 sm:px-6 lg:mx-0 lg:w-56 lg:shrink-0 lg:flex-col lg:gap-5 lg:overflow-visible lg:border-b-0 lg:px-0 lg:pb-0"
                        aria-label="Settings sections"
                    >
                        {navSections.map((section) => (
                            <div key={section.label} className="contents lg:flex lg:flex-col lg:gap-1">
                                <p className="hidden px-2.5 pb-1 text-xs font-medium text-muted-foreground/80 lg:block">
                                    {section.label}
                                </p>
                                {section.items.map((item) => {
                                    const active = url.startsWith(item.href);
                                    return (
                                        <Link
                                            key={item.href}
                                            href={item.href}
                                            aria-current={active ? 'page' : undefined}
                                            className={cn(
                                                'shrink-0 rounded-md px-2.5 py-2 text-base font-medium whitespace-nowrap focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring sm:text-sm lg:py-1.5',
                                                active
                                                    ? 'bg-muted text-foreground'
                                                    : 'text-muted-foreground hover:bg-muted hover:text-foreground',
                                            )}
                                        >
                                            {item.title}
                                        </Link>
                                    );
                                })}
                            </div>
                        ))}
                    </nav>

                    <div className="min-w-0 flex-1">{children}</div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
