import { Head } from '@inertiajs/react';

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import CompanyForm, { type CompanyFormOptions, type CompanyFormValues } from '@/components/companies/CompanyForm';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

interface CompanyRow extends Partial<CompanyFormValues> {
    id: number;
    name: string;
}

interface Props {
    company: CompanyRow;
    options: CompanyFormOptions;
}

export default function EditCompanyPage({ company, options }: Props) {
    return (
        <AuthenticatedLayout>
            <Head title={`Edit ${company.name}`} />
            <div className="mx-auto max-w-2xl space-y-6">
                <header>
                    <h1 className="text-2xl font-semibold tracking-tight">Edit {company.name}</h1>
                    <p className="text-sm text-muted-foreground">
                        Changing the ATS slug will redirect future scrapes. Existing roles stay linked.
                    </p>
                </header>
                <Card>
                    <CardHeader>
                        <CardTitle>Details</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <CompanyForm
                            mode="edit"
                            initial={{
                                name: company.name ?? '',
                                website: company.website ?? '',
                                careers_url: company.careers_url ?? '',
                                domain: company.domain ?? '',
                                ats_type: company.ats_type ?? '',
                                ats_slug: company.ats_slug ?? '',
                                tier: company.tier ?? 'C',
                                status: company.status ?? 'researching',
                            }}
                            options={options}
                            action={`/companies/${company.id}`}
                            method="put"
                            cancelHref="/companies"
                        />
                    </CardContent>
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
