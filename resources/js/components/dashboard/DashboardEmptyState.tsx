import { Link } from '@inertiajs/react';

import { Button } from '@/components/ui/button';

export default function DashboardEmptyState() {
    return (
        <div className="max-w-xl py-8">
            <h2 className="text-lg font-semibold text-foreground">Welcome to VantageJobs</h2>
            <p className="mt-2 text-base text-pretty text-muted-foreground sm:text-sm">
                Add a company to start tracking its roles, or bulk import a list to get going faster.
            </p>
            <div className="mt-6 flex flex-wrap items-center gap-3">
                <Button asChild size="sm">
                    <Link href="/companies/create">Add company</Link>
                </Button>
                <Button asChild size="sm" variant="outline">
                    <Link href="/import">Bulk import</Link>
                </Button>
            </div>
        </div>
    );
}
