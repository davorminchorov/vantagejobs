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
                <header className="border-b">
                    <div className="mx-auto flex h-16 w-full max-w-5xl items-center justify-between px-4 sm:px-6">
                        <Link
                            href="/"
                            className="rounded-md px-1 py-1 text-base font-semibold tracking-tight focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        >
                            VantageJobs
                        </Link>
                        <nav aria-label="Primary" className="flex items-center gap-3 text-sm">
                            <a
                                href="https://github.com"
                                target="_blank"
                                rel="noopener noreferrer"
                                className="text-muted-foreground hover:text-foreground"
                            >
                                Source
                            </a>
                            {signedIn ? (
                                <Button asChild size="sm">
                                    <Link href="/dashboard">
                                        Dashboard <ArrowRight className="ml-1 size-3.5" aria-hidden="true" />
                                    </Link>
                                </Button>
                            ) : (
                                <Button asChild size="sm">
                                    <Link href="/login">Sign in</Link>
                                </Button>
                            )}
                        </nav>
                    </div>
                </header>

                <main className="flex-1">
                    <section className="mx-auto w-full max-w-5xl px-4 pt-20 sm:px-6 sm:pt-28">
                        <p className="font-mono text-xs font-medium uppercase tracking-wide text-muted-foreground">
                            Personal job-hunting platform
                        </p>
                        <h1 className="mt-3 max-w-3xl bg-gradient-to-r from-teal-600 to-emerald-500 bg-clip-text text-4xl font-semibold tracking-tight text-transparent sm:text-5xl">
                            A queryable, signal-aware memory of your relationship with the job market.
                        </h1>
                        <p className="mt-5 max-w-2xl text-base text-muted-foreground sm:text-lg">
                            Watches companies you care about. Remembers every role, note, and decision.
                            Surfaces what changed since you last looked. Not a SaaS. Not for anyone else.
                        </p>
                        <div className="mt-8 flex items-center gap-3">
                            {signedIn ? (
                                <Button asChild>
                                    <Link href="/dashboard">
                                        Open dashboard <ArrowRight className="ml-1.5 size-4" aria-hidden="true" />
                                    </Link>
                                </Button>
                            ) : (
                                <Button asChild>
                                    <Link href="/login">
                                        Sign in <ArrowRight className="ml-1.5 size-4" aria-hidden="true" />
                                    </Link>
                                </Button>
                            )}
                            <span className="text-sm text-muted-foreground">
                                Invite-only. No public registration.
                            </span>
                        </div>
                    </section>

                    <section className="mx-auto w-full max-w-5xl px-4 pb-20 pt-24 sm:px-6">
                        <div className="grid gap-6 border-t pt-12 sm:grid-cols-3">
                            <Capability
                                label="Watch"
                                copy="ATS scrapers for Greenhouse, Lever, and Ashby. Conditional GETs, diff and dedup, every change captured as an event."
                            />
                            <Capability
                                label="Remember"
                                copy="Per-company dossiers. Roles, timelines, notes, and tags persist across years and job switches."
                            />
                            <Capability
                                label="Find"
                                copy="Postgres full-text search behind Cmd/Ctrl-K. Prefix-matched, ranked, sub-100 ms."
                            />
                        </div>
                    </section>
                </main>

                <footer className="border-t">
                    <div className="mx-auto flex h-12 w-full max-w-5xl items-center justify-between px-4 text-xs text-muted-foreground sm:px-6">
                        <span>
                            Phase <span className="font-mono tabular-nums">1</span> shipped ·{' '}
                            <span className="font-mono tabular-nums">May 2026</span>
                        </span>
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
        <div className="space-y-2 rounded-2xl border border-border bg-card p-5 shadow-sm transition-all hover:-translate-y-0.5 hover:shadow-md">
            <span className="font-mono text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">
                {label}
            </span>
            <p className="text-sm text-card-foreground/90">{copy}</p>
        </div>
    );
}
