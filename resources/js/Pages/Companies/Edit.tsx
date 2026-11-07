import { Head } from '@inertiajs/react';

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import CompanyForm, { type CompanyFormOptions, type CompanyFormValues } from '@/components/companies/CompanyForm';
import PageHeader from '@/components/PageHeader';

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
            <div className="flex max-w-2xl flex-col gap-8">
                <PageHeader
                    title={`Edit ${company.name}`}
                    subtitle="Changing the ATS slug will redirect future scrapes. Existing roles stay linked."
                />
                <CompanyForm
                    mode="edit"
                    initial={{
                        name: company.name ?? '',
                        website: company.website ?? '',
                        careers_url: company.careers_url ?? '',
                        domain: company.domain ?? '',
                        ats_type: company.ats_type ?? '',
                        ats_slug: company.ats_slug ?? '',
                        ats_host: company.ats_host ?? '',
                        tier: company.tier ?? 'C',
                        status: company.status ?? 'researching',
                    }}
                    options={options}
                    action={`/companies/${company.id}`}
                    method="put"
                    cancelHref="/companies"
                />
            </div>
        </AuthenticatedLayout>
    );
}
