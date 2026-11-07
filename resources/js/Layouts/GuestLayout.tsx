import type { ReactNode } from 'react';

interface GuestLayoutProps {
    children: ReactNode;
    title?: string;
}

export default function GuestLayout({ children, title }: GuestLayoutProps): ReactNode {
    return (
        <div className="flex min-h-svh w-full flex-col items-center justify-center bg-background px-4 py-10 text-foreground sm:px-6">
            <div className="flex w-full max-w-sm flex-col gap-8">
                <a
                    href="/"
                    aria-label="Homepage"
                    className="self-start rounded-md text-xl font-semibold tracking-tight focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                >
                    VantageJobs
                </a>
                <div className="flex flex-col gap-6">
                    {title ? (
                        <h1 className="text-2xl font-semibold tracking-tight text-balance">{title}</h1>
                    ) : null}
                    {children}
                </div>
            </div>
        </div>
    );
}
