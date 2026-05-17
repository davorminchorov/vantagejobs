import type { ReactNode } from 'react';

import { Card, CardContent } from '@/components/ui/card';

interface GuestLayoutProps {
    children: ReactNode;
    title?: string;
}

export default function GuestLayout({ children, title }: GuestLayoutProps): ReactNode {
    return (
        <div className="bg-muted text-foreground flex min-h-svh w-full flex-col items-center justify-center px-4 py-10 sm:px-6">
            <div className="flex w-full max-w-md flex-col items-center gap-6">
                <a
                    href="/"
                    className="text-foreground text-xl font-semibold tracking-tight focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring rounded-md px-2 py-1"
                >
                    VantageJobs
                </a>
                <Card className="w-full">
                    {title ? (
                        <div className="border-b px-6 pt-6 pb-4">
                            <h1 className="text-lg font-semibold tracking-tight">{title}</h1>
                        </div>
                    ) : null}
                    <CardContent className="p-6">{children}</CardContent>
                </Card>
            </div>
        </div>
    );
}
