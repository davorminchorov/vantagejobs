import { Pencil } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { SectionLabel } from '@/components/ui/section-label';
import { RelativeDate } from '@/lib/dates';
import { cn } from '@/lib/utils';

import { CorrectionEditor } from './CorrectionEditor';
import { EditablePill } from './EditablePill';
import { ListingActions } from './ListingActions';
import { AUTO_DISMISS_REASON_LABELS, compLabel, hackerNewsCommentUrl, postingUrl, regionLabel } from './helpers';
import type { CorrectionValue, EditableField, Listing, ListingAction } from './types';

export interface ListingDetailProps {
    listing: Listing;
    debug: boolean;
    editingField: EditableField | null;
    /** Other loaded listings from the same company that aren't selected yet. */
    sameCompanyIds: number[];
    onAction: (verb: ListingAction) => void;
    onEdit: (field: EditableField) => void;
    onCancelEdit: () => void;
    onSave: (field: EditableField, value: CorrectionValue) => void;
    onSaveFixture: () => void;
    onReparse: (force: boolean) => void;
    onSelectSameCompany: () => void;
    className?: string;
}

/**
 * Everything needed to decide on one listing without leaving the page: the
 * parsed facts (each one correctable), the actions and the posting text.
 */
export function ListingDetail({
    listing,
    debug,
    editingField,
    sameCompanyIds,
    onAction,
    onEdit,
    onCancelEdit,
    onSave,
    onSaveFixture,
    onReparse,
    onSelectSameCompany,
    className,
}: ListingDetailProps) {
    const titleHref = postingUrl(listing) ?? hackerNewsCommentUrl(listing);
    const company = listing.parsed_company_name;

    return (
        <article className={cn('group/row flex flex-col gap-5', className)} aria-label="Listing details">
            <header className="flex flex-col gap-3">
                <div className="flex flex-wrap items-center gap-x-2 gap-y-1 text-base text-muted-foreground sm:text-sm">
                    <span className="font-medium text-foreground">
                        <EditablePill
                            listing={listing}
                            field="company_name"
                            label={company ?? '(company unknown)'}
                            className="-ml-1"
                            onEdit={onEdit}
                        />
                    </span>
                    {listing.source ? <Badge variant="secondary">{listing.source.name}</Badge> : null}
                    {listing.posted_at !== null ? (
                        <span className="whitespace-nowrap">
                            Posted <RelativeDate iso={listing.posted_at} />
                        </span>
                    ) : null}
                    {listing.auto_dismiss_reason ? (
                        <Badge variant="warning">
                            {AUTO_DISMISS_REASON_LABELS[listing.auto_dismiss_reason] ??
                                listing.auto_dismiss_reason.replace('_', ' ')}
                        </Badge>
                    ) : null}
                </div>

                <div className="flex items-start gap-2">
                    <h2 className="min-w-0 text-xl font-semibold text-pretty sm:text-lg">
                        {titleHref ? (
                            <a href={titleHref} target="_blank" rel="noopener noreferrer" className="hover:underline">
                                {listing.parsed_role_title ?? '(role unknown)'}
                            </a>
                        ) : (
                            (listing.parsed_role_title ?? '(role unknown)')
                        )}
                    </h2>
                    <button
                        type="button"
                        onClick={() => onEdit('role_title')}
                        className="mt-1 shrink-0 rounded-md p-1 text-muted-foreground hover:bg-accent hover:text-foreground focus-visible:outline-2 focus-visible:outline-ring"
                        aria-label="Edit role title"
                        title="Edit role title"
                    >
                        <Pencil aria-hidden="true" className="size-3.5" />
                    </button>
                </div>

                <dl className="flex flex-wrap gap-x-5 gap-y-2 text-base sm:text-sm">
                    <Fact label="Pay">
                        <EditablePill
                            listing={listing}
                            field="comp_min_usd"
                            label={listing.has_comp && listing.parsed_comp_min_usd != null ? compLabel(listing) : 'Add pay'}
                            placeholder={!listing.has_comp || listing.parsed_comp_min_usd == null}
                            revealed
                            onEdit={onEdit}
                        />
                    </Fact>
                    <Fact label="Seniority">
                        <EditablePill
                            listing={listing}
                            field="seniority"
                            label={listing.parsed_seniority ?? 'Add seniority'}
                            placeholder={listing.parsed_seniority == null}
                            revealed
                            onEdit={onEdit}
                        />
                    </Fact>
                    <Fact label="Remote">
                        <EditablePill
                            listing={listing}
                            field="remote_type"
                            label={listing.parsed_remote_type?.replace('_', ' ') ?? 'Add remote type'}
                            placeholder={listing.parsed_remote_type == null}
                            revealed
                            onEdit={onEdit}
                        />
                    </Fact>
                    <Fact label="Region">
                        <EditablePill
                            listing={listing}
                            field="region"
                            label={listing.location_bucket ? regionLabel(listing.location_bucket) : 'Add region'}
                            placeholder={listing.location_bucket == null}
                            revealed
                            onEdit={onEdit}
                        />
                    </Fact>
                </dl>

                <div className="-ml-1 flex flex-wrap items-center gap-1.5 has-[span]:ml-0">
                    {(listing.stack_tags ?? []).map((tag) => (
                        <Badge key={tag} variant="secondary">
                            {tag}
                        </Badge>
                    ))}
                    <button
                        type="button"
                        onClick={() => onEdit('stack_tags')}
                        className="rounded-md px-1 text-xs text-muted-foreground hover:bg-accent hover:text-foreground focus-visible:outline-2 focus-visible:outline-ring"
                    >
                        {listing.stack_tags && listing.stack_tags.length > 0 ? 'Edit stack' : 'Add stack'}
                    </button>
                </div>
            </header>

            <ListingActions
                listing={listing}
                isFocused
                debug={debug}
                onAction={onAction}
                onReparse={onReparse}
                onSaveFixture={onSaveFixture}
                className="sm:flex-wrap sm:gap-2"
            />

            {editingField !== null ? (
                <CorrectionEditor
                    key={`${listing.id}-${editingField}`}
                    listing={listing}
                    field={editingField}
                    debug={debug}
                    onSave={(value) => onSave(editingField, value)}
                    onCancel={onCancelEdit}
                    onSaveFixture={onSaveFixture}
                />
            ) : null}

            {company && sameCompanyIds.length > 0 ? (
                <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 rounded-lg bg-muted/60 px-3 py-2.5 text-base sm:text-sm">
                    <p className="text-pretty text-muted-foreground">
                        <span className="tabular-nums">{sameCompanyIds.length}</span> more{' '}
                        {sameCompanyIds.length === 1 ? 'listing' : 'listings'} from{' '}
                        <span className="font-medium text-foreground">{company}</span> loaded
                    </p>
                    <Button type="button" size="sm" variant="outline" onClick={onSelectSameCompany}>
                        Select all from {company}
                    </Button>
                </div>
            ) : null}

            <section className="flex flex-col gap-2 border-t border-border pt-5">
                <SectionLabel>Description</SectionLabel>
                {listing.body_text ? (
                    <div className="max-w-prose text-base/7 break-words whitespace-pre-line text-pretty sm:text-sm/6">
                        {listing.body_text}
                    </div>
                ) : (
                    <p className="text-base text-pretty text-muted-foreground sm:text-sm">
                        No description was captured for this listing. Open the posting to read it.
                    </p>
                )}
            </section>
        </article>
    );
}

function Fact({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div className="flex items-center gap-1.5">
            <dt className="text-muted-foreground">{label}</dt>
            <dd>{children}</dd>
        </div>
    );
}
