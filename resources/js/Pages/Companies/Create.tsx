import { Head } from '@inertiajs/react';

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import CompanyForm, { type CompanyFormOptions } from '@/components/companies/CompanyForm';
import PageHeader from '@/components/PageHeader';

interface Props {
    options: CompanyFormOptions;
}

export default function CreateCompanyPage({ options }: Props) {
    return (
        <AuthenticatedLayout>
            <Head title="Add company" />
            <div className="flex max-w-2xl flex-col gap-8">
                <PageHeader
                    title="Add a company"
                    subtitle="Paste a careers URL and we'll detect Greenhouse, Lever, or Ashby automatically. You can refine everything later. Defaults to Tier C, status researching."
                />
                <CompanyForm
                    mode="create"
                    initial={{}}
                    options={options}
                    action="/companies"
                    method="post"
                    cancelHref="/companies"
                />
            </div>
        </AuthenticatedLayout>
    );
}
