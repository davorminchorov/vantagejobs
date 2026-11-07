import { Head } from '@inertiajs/react';

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import CompanyForm, { type CompanyFormOptions, type CompanyFormValues } from '@/components/companies/CompanyForm';
import PageHeader from '@/components/PageHeader';

interface Props {
    options: CompanyFormOptions;
    initial: Partial<CompanyFormValues>;
    /** The funding headline this company is being tracked from, if any. */
    from_announcement: { title: string; url: string; source: string } | null;
}

function safeUrl(url: string): string | null {
    try {
        const parsed = new URL(url);
        return parsed.protocol === 'http:' || parsed.protocol === 'https:' ? parsed.toString() : null;
    } catch {
        return null;
    }
}

export default function CreateCompanyPage({ options, initial, from_announcement }: Props) {
    const announcementUrl = from_announcement ? safeUrl(from_announcement.url) : null;

    return (
        <AuthenticatedLayout>
            <Head title="Add company" />
            <div className="flex max-w-2xl flex-col gap-8">
                <PageHeader
                    title="Add a company"
                    subtitle="Paste a careers URL and we'll detect Greenhouse, Lever, or Ashby automatically. You can refine everything later. Defaults to Tier C, status researching."
                />
                {from_announcement ? (
                    <p className="rounded-md bg-muted px-3 py-2 text-base text-pretty text-muted-foreground sm:text-sm">
                        From {from_announcement.source}:{' '}
                        {announcementUrl ? (
                            <a href={announcementUrl} target="_blank" rel="noopener noreferrer" className="text-foreground hover:underline">
                                {from_announcement.title}
                            </a>
                        ) : (
                            <span className="text-foreground">{from_announcement.title}</span>
                        )}
                        . Add the website so the job board can be detected. Saving also records this round on the company.
                    </p>
                ) : null}
                <CompanyForm mode="create" initial={initial} options={options} action="/companies" method="post" cancelHref="/companies" />
            </div>
        </AuthenticatedLayout>
    );
}
