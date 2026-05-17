import { Head } from '@inertiajs/react';
import type { ReactNode } from 'react';

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';

export default function Dashboard(): ReactNode {
    return (
        <AuthenticatedLayout>
            <Head title="Dashboard" />
            <Card className="mx-auto max-w-2xl">
                <CardHeader>
                    <CardTitle>No companies yet</CardTitle>
                    <CardDescription>
                        Phase 0 scaffold — add companies in Phase 1.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <Button disabled className="min-h-11">
                        Add company
                    </Button>
                </CardContent>
            </Card>
        </AuthenticatedLayout>
    );
}
