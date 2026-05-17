import { Head } from '@inertiajs/react';

export default function Welcome() {
    return (
        <>
            <Head title="Welcome" />
            <main className="flex min-h-full items-center justify-center p-8">
                <section className="max-w-xl space-y-4 text-center">
                    <h1 className="text-3xl font-semibold tracking-tight">VantageJobs</h1>
                    <p className="text-muted-foreground">
                        Personal job-hunting platform. Phase 0 scaffold is live.
                    </p>
                </section>
            </main>
        </>
    );
}
