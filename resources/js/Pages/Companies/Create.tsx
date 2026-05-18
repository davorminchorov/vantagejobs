import { Head } from '@inertiajs/react';

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import CompanyForm, { type CompanyFormOptions } from '@/components/companies/CompanyForm';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';

interface Props {
    options: CompanyFormOptions;
}

export default function CreateCompanyPage({ options }: Props) {
    return (
        <AuthenticatedLayout>
            <Head title="Add company" />
            <div className="mx-auto max-w-2xl space-y-6">
                <header>
                    <h1 className="text-2xl font-semibold tracking-tight">Add a company</h1>
                    <p className="text-sm text-muted-foreground">
                        Paste a careers URL — we'll detect Greenhouse, Lever, or Ashby automatically.
                    </p>
                </header>
                <Card>
                    <CardHeader>
                        <CardTitle>Details</CardTitle>
                        <CardDescription>You can refine everything later. Defaults to Tier C, status researching.</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <CompanyForm
                            mode="create"
                            initial={{}}
                            options={options}
                            action="/companies"
                            method="post"
                            cancelHref="/companies"
                        />
                    </CardContent>
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
