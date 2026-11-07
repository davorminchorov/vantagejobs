import { Head } from '@inertiajs/react';

import TodayDashboard from '@/components/dashboard/TodayDashboard';
import { type DashboardProps } from '@/components/dashboard/types';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';

export default function DashboardPage(props: DashboardProps) {
    return (
        <AuthenticatedLayout>
            <Head title="Dashboard" />
            <TodayDashboard {...props} />
        </AuthenticatedLayout>
    );
}
