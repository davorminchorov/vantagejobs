import { ChevronDown, SlidersHorizontal, X } from 'lucide-react';
import type { ReactNode } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuCheckboxItem, DropdownMenuContent, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetTrigger } from '@/components/ui/sheet';

import {
    activeChips,
    hasActiveFilters,
    humanize,
    LAST_CHECKED_LABELS,
    SORT_LABELS,
    tierLabel,
    toggleArrayValue,
    type CompanyFilterOptions,
    type CompanyFilters,
} from './filters';

interface CompanyFilterBarProps {
    filters: CompanyFilters;
    options: CompanyFilterOptions;
    onChange: (patch: Partial<CompanyFilters>) => void;
    onClear: () => void;
}

export default function CompanyFilterBar({ filters, options, onChange, onClear }: CompanyFilterBarProps) {
    const chips = activeChips(filters);
    const anyActive = hasActiveFilters(filters);

    const signalCount =
        (filters.has_open_roles === true ? 1 : 0) +
        (filters.new_this_week ? 1 : 0) +
        (filters.recently_funded === true ? 1 : 0) +
        (filters.unscraped ? 1 : 0) +
        (filters.last_checked === 'older_than_7d' ? 1 : 0);

    const moreCount =
        filters.status.length +
        filters.ats_type.length +
        filters.stage.length +
        (filters.domain ? 1 : 0) +
        (filters.last_checked && filters.last_checked !== 'older_than_7d' ? 1 : 0) +
        (filters.has_open_roles === false ? 1 : 0) +
        (filters.recently_funded === false ? 1 : 0);

    return (
        <div className="flex flex-col gap-3">
            <div className="flex flex-wrap items-center gap-2">
                <Input
                    name="q"
                    type="search"
                    aria-label="Search companies"
                    placeholder="Search name, domain, slug"
                    className="w-full sm:w-64"
                    value={filters.q ?? ''}
                    onChange={(e) => onChange({ q: e.target.value === '' ? null : e.target.value })}
                />

                <FilterMenu label="Tier" count={filters.tier.length}>
                    {options.tiers.map((tier) => (
                        <MenuCheckbox
                            key={tier}
                            checked={filters.tier.includes(tier)}
                            onToggle={() => onChange({ tier: toggleArrayValue(filters.tier, tier) })}
                        >
                            {tierLabel(tier)}
                        </MenuCheckbox>
                    ))}
                </FilterMenu>

                <FilterMenu label="Signals" count={signalCount}>
                    <MenuCheckbox
                        checked={filters.has_open_roles === true}
                        onToggle={() => onChange({ has_open_roles: filters.has_open_roles === true ? null : true })}
                    >
                        Hiring now
                    </MenuCheckbox>
                    <MenuCheckbox checked={filters.new_this_week} onToggle={() => onChange({ new_this_week: !filters.new_this_week })}>
                        New this week
                    </MenuCheckbox>
                    <MenuCheckbox
                        checked={filters.recently_funded === true}
                        onToggle={() => onChange({ recently_funded: filters.recently_funded === true ? null : true })}
                    >
                        Funded recently
                    </MenuCheckbox>
                    <MenuCheckbox checked={filters.unscraped} onToggle={() => onChange({ unscraped: !filters.unscraped })}>
                        Not being scraped
                    </MenuCheckbox>
                    <MenuCheckbox
                        checked={filters.last_checked === 'older_than_7d'}
                        onToggle={() => onChange({ last_checked: filters.last_checked === 'older_than_7d' ? null : 'older_than_7d' })}
                    >
                        Stale
                    </MenuCheckbox>
                </FilterMenu>

                <MoreFilters filters={filters} options={options} onChange={onChange} count={moreCount} />

                <label className="ml-auto flex items-center gap-2 text-base text-muted-foreground sm:text-sm">
                    <span className="whitespace-nowrap">Sort by</span>
                    <NativeSelect name="sort" value={filters.sort} onChange={(e) => onChange({ sort: e.target.value })}>
                        {options.sorts.map((sort) => (
                            <option key={sort} value={sort}>
                                {SORT_LABELS[sort] ?? sort}
                            </option>
                        ))}
                    </NativeSelect>
                </label>
            </div>

            {anyActive ? (
                <div className="flex flex-wrap items-center gap-2 text-base sm:text-sm" aria-label="Active filters" role="group">
                    <span className="text-muted-foreground">Active</span>
                    {chips.map((chip) => (
                        <Badge key={chip.key} variant="secondary" className="gap-1 pr-0.5">
                            {chip.label}
                            <button
                                type="button"
                                aria-label={`Remove filter ${chip.label}`}
                                onClick={() => onChange(chip.patch)}
                                className="inline-flex size-6 items-center justify-center rounded-sm hover:bg-foreground/10 focus-visible:outline-2 focus-visible:outline-ring sm:size-5"
                            >
                                <X className="size-3" aria-hidden="true" />
                            </button>
                        </Badge>
                    ))}
                    <button
                        type="button"
                        onClick={onClear}
                        className="min-h-8 rounded-md px-1 text-muted-foreground underline-offset-4 hover:text-foreground hover:underline focus-visible:outline-2 focus-visible:outline-ring"
                    >
                        Clear all
                    </button>
                </div>
            ) : null}
        </div>
    );
}

function FilterMenu({ label, count, children }: { label: string; count: number; children: ReactNode }) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant="outline" aria-label={count > 0 ? `${label}, ${count} selected` : label}>
                    {label}
                    {count > 0 ? <span className="tabular-nums text-primary">{count}</span> : null}
                    <ChevronDown className="text-muted-foreground" aria-hidden="true" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" className="min-w-48">
                {children}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/** Checkbox row that keeps the menu open so several values can be picked in one go. */
function MenuCheckbox({ checked, onToggle, children }: { checked: boolean; onToggle: () => void; children: ReactNode }) {
    return (
        <DropdownMenuCheckboxItem
            checked={checked}
            onCheckedChange={onToggle}
            onSelect={(e) => e.preventDefault()}
            className="py-2 text-base sm:py-1.5 sm:text-sm"
        >
            {children}
        </DropdownMenuCheckboxItem>
    );
}

interface MoreFiltersProps {
    filters: CompanyFilters;
    options: CompanyFilterOptions;
    onChange: (patch: Partial<CompanyFilters>) => void;
    count: number;
}

function MoreFilters({ filters, options, onChange, count }: MoreFiltersProps) {
    return (
        <Sheet>
            <SheetTrigger asChild>
                <Button variant="outline" aria-label={count > 0 ? `More filters, ${count} selected` : 'More filters'}>
                    <SlidersHorizontal aria-hidden="true" />
                    More
                    {count > 0 ? <span className="tabular-nums text-primary">{count}</span> : null}
                </Button>
            </SheetTrigger>
            <SheetContent side="right" className="w-80 overflow-y-auto">
                <SheetHeader>
                    <SheetTitle>More filters</SheetTitle>
                </SheetHeader>
                <div className="mt-4 flex flex-col gap-5 px-1 pb-6">
                    <CheckboxGroup
                        label="Status"
                        selected={filters.status}
                        options={options.statuses.map((s) => ({ value: s, label: humanize(s) }))}
                        onChange={(next) => onChange({ status: next })}
                    />
                    <CheckboxGroup
                        label="ATS type"
                        selected={filters.ats_type}
                        options={options.ats_types.map((a) => ({ value: a, label: humanize(a) }))}
                        onChange={(next) => onChange({ ats_type: next })}
                    />
                    {options.stages.length > 0 ? (
                        <CheckboxGroup
                            label="Funding stage"
                            selected={filters.stage}
                            options={options.stages.map((s) => ({ value: s, label: s }))}
                            onChange={(next) => onChange({ stage: next })}
                        />
                    ) : null}
                    <Field label="Last checked" htmlFor="more-last-checked">
                        <NativeSelect
                            id="more-last-checked"
                            name="last_checked"
                            wrapperClassName="w-full"
                            value={filters.last_checked ?? ''}
                            onChange={(e) => onChange({ last_checked: e.target.value === '' ? null : e.target.value })}
                        >
                            <option value="">Any time</option>
                            {options.last_checked.map((value) => (
                                <option key={value} value={value}>
                                    {LAST_CHECKED_LABELS[value] ?? value}
                                </option>
                            ))}
                        </NativeSelect>
                    </Field>
                    <Field label="Open roles" htmlFor="more-open-roles">
                        <NativeSelect
                            id="more-open-roles"
                            name="has_open_roles"
                            wrapperClassName="w-full"
                            value={filters.has_open_roles === null ? '' : filters.has_open_roles ? '1' : '0'}
                            onChange={(e) => onChange({ has_open_roles: e.target.value === '' ? null : e.target.value === '1' })}
                        >
                            <option value="">Any</option>
                            <option value="1">Has open roles</option>
                            <option value="0">No open roles</option>
                        </NativeSelect>
                    </Field>
                    <Field label="Domain contains" htmlFor="more-domain">
                        <Input
                            id="more-domain"
                            name="domain"
                            type="search"
                            placeholder="example.com"
                            value={filters.domain ?? ''}
                            onChange={(e) => onChange({ domain: e.target.value === '' ? null : e.target.value })}
                        />
                    </Field>
                </div>
            </SheetContent>
        </Sheet>
    );
}

function Field({ label, htmlFor, children }: { label: string; htmlFor: string; children: ReactNode }) {
    return (
        <div className="flex flex-col gap-1.5">
            <label htmlFor={htmlFor} className="text-sm font-medium text-muted-foreground">
                {label}
            </label>
            {children}
        </div>
    );
}

interface CheckboxGroupProps {
    label: string;
    selected: string[];
    options: { value: string; label: string }[];
    onChange: (next: string[]) => void;
}

function CheckboxGroup({ label, selected, options, onChange }: CheckboxGroupProps) {
    return (
        <fieldset className="flex flex-col gap-1">
            <legend className="mb-1.5 text-sm font-medium text-muted-foreground">{label}</legend>
            {options.map((option) => (
                <label key={option.value} className="flex min-h-8 cursor-pointer items-center gap-2 text-base sm:text-sm">
                    <input
                        type="checkbox"
                        name={`${label}[]`}
                        className="size-4 accent-primary"
                        checked={selected.includes(option.value)}
                        onChange={() => onChange(toggleArrayValue(selected, option.value))}
                    />
                    <span>{option.label}</span>
                </label>
            ))}
        </fieldset>
    );
}
