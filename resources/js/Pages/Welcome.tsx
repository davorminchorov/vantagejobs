import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';

import { Button } from '@/components/ui/button';
import type { PageProps } from '@/types';

export default function Welcome() {
    const { auth } = usePage<PageProps>().props;
    const signedIn = auth.user !== null;

    return (
        <>
            <Head title="VantageJobs" />
            <div className="bg-background text-foreground flex min-h-svh flex-col">
                <header className="border-b border-border">
                    <div className="mx-auto flex h-16 w-full max-w-5xl items-center justify-between px-4 sm:px-6">
                        <Link
                            href="/"
                            className="rounded-md text-base font-semibold tracking-tight focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                        >
                            VantageJobs
                        </Link>
                        <nav aria-label="Primary" className="flex items-center gap-4 text-base sm:text-sm">
                            <a
                                href="https://github.com"
                                target="_blank"
                                rel="noopener noreferrer"
                                className="text-muted-foreground hover:text-foreground"
                            >
                                Source
                            </a>
                            {signedIn ? (
                                <Button asChild size="sm" variant="outline">
                                    <Link href="/dashboard">
                                        Dashboard <ArrowRight aria-hidden="true" />
                                    </Link>
                                </Button>
                            ) : (
                                <Button asChild size="sm" variant="outline">
                                    <Link href="/login">Sign in</Link>
                                </Button>
                            )}
                        </nav>
                    </div>
                </header>

                <main className="flex-1">
                    <section className="mx-auto w-full max-w-5xl px-4 pt-20 sm:px-6 sm:pt-28">
                        <p className="text-sm font-medium text-muted-foreground">Personal job-hunting platform</p>
                        <h1 className="mt-3 max-w-3xl text-4xl font-semibold tracking-tight text-balance sm:text-5xl">
                            A queryable, signal-aware memory of your relationship with the job market.
                        </h1>
                        <p className="mt-5 max-w-2xl text-lg text-pretty text-muted-foreground">
                            Watches companies you care about. Remembers every role, note, and decision. Surfaces what changed since you last
                            looked. Not a SaaS. Not for anyone else.
                        </p>
                        <div className="mt-8 flex flex-wrap items-center gap-x-4 gap-y-3">
                            {signedIn ? (
                                <Button asChild>
                                    <Link href="/dashboard">
                                        Open dashboard <ArrowRight aria-hidden="true" />
                                    </Link>
                                </Button>
                            ) : (
                                <Button asChild>
                                    <Link href="/login">
                                        Sign in <ArrowRight aria-hidden="true" />
                                    </Link>
                                </Button>
                            )}
                            <span className="text-base text-muted-foreground sm:text-sm">Invite-only. No public registration.</span>
                        </div>
                    </section>

                    <section className="mx-auto w-full max-w-5xl px-4 pb-20 pt-24 sm:px-6">
                        <dl className="grid gap-8 border-t border-border pt-12 sm:grid-cols-3">
                            <Capability
                                label="Watch"
                                copy="ATS scrapers for 13 job boards, from Greenhouse to Workday, plus 15 job aggregators. Conditional GETs, diff and dedup, every change captured as an event."
                            />
                            <Capability
                                label="Remember"
                                copy="Per-company dossiers. Roles, timelines, notes, and tags persist across years and job switches."
                            />
                            <Capability
                                label="Find"
                                copy="Postgres full-text search behind Cmd/Ctrl-K. Prefix-matched, ranked, sub-100 ms."
                            />
                        </dl>
                    </section>
                </main>

                <footer className="border-t border-border">
                    <div className="mx-auto flex h-12 w-full max-w-5xl items-center justify-end px-4 text-xs text-muted-foreground sm:px-6">
                        <Link href="/up" className="hover:text-foreground">
                            Status
                        </Link>
                    </div>
                </footer>
            </div>
        </>
    );
}

function Capability({ label, copy }: { label: string; copy: string }) {
    return (
        <div className="flex flex-col gap-1.5">
            <dt className="text-base font-medium text-foreground sm:text-sm">{label}</dt>
            <dd className="text-base text-pretty text-muted-foreground sm:text-sm">{copy}</dd>
        </div>
    );
}
