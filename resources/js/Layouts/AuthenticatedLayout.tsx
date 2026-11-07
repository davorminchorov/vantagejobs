import { usePage } from '@inertiajs/react';
import { type ReactNode } from 'react';

import SidebarShell from '@/components/app-shell/SidebarShell';
import GlobalCommandPalette from '@/components/search/GlobalCommandPalette';
import { useAppearance } from '@/hooks/use-appearance';
import type { PageProps } from '@/types';

interface AuthenticatedLayoutProps {
    children: ReactNode;
    title?: string;
    header?: ReactNode;
}

export default function AuthenticatedLayout({ children, header }: AuthenticatedLayoutProps): ReactNode {
    const page = usePage<PageProps>();
    const user = page.props.auth.user ?? null;
    const currentUrl = page.url.split('?')[0] ?? '/';
    const { appearance, setAppearance } = useAppearance();

    return (
        <div className="bg-background text-foreground isolate flex min-h-svh flex-col">
            <SidebarShell user={user} currentUrl={currentUrl} appearance={appearance} onAppearanceChange={setAppearance} />

            <div className="flex flex-1 flex-col lg:pl-64">
                {header ? (
                    <div className="border-b">
                        <div className="mx-auto w-full max-w-[1600px] px-4 py-6 sm:px-6">{header}</div>
                    </div>
                ) : null}

                <main className="mx-auto w-full max-w-[1600px] flex-1 px-4 py-6 sm:px-6">{children}</main>
            </div>

            <GlobalCommandPalette />
        </div>
    );
}
