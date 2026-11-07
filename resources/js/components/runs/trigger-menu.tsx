import { ChevronDown } from 'lucide-react';

import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

export interface TriggerOptions {
    ats_types: { value: string; label: string; count: number }[];
    aggregators: { value: string; label: string }[];
}

interface TriggerMenuProps {
    options: TriggerOptions;
    onTrigger: (scope: string) => void;
}

export function TriggerMenu({ options, onTrigger }: TriggerMenuProps) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button type="button">
                    Run scrapers
                    <ChevronDown aria-hidden="true" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="max-h-96 w-64">
                <DropdownMenuItem onSelect={() => onTrigger('all')}>Run everything</DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuLabel className="text-muted-foreground font-medium">ATS</DropdownMenuLabel>
                <DropdownMenuItem onSelect={() => onTrigger('ats')}>Run all ATS</DropdownMenuItem>
                {options.ats_types.map((t) => (
                    <DropdownMenuItem key={t.value} onSelect={() => onTrigger(`ats:${t.value}`)}>
                        {t.label}
                        <span className="ml-auto text-muted-foreground tabular-nums">{t.count}</span>
                    </DropdownMenuItem>
                ))}
                <DropdownMenuSeparator />
                <DropdownMenuLabel className="text-muted-foreground font-medium">Aggregators</DropdownMenuLabel>
                <DropdownMenuItem onSelect={() => onTrigger('aggregators')}>Run all aggregators</DropdownMenuItem>
                {options.aggregators.map((a) => (
                    <DropdownMenuItem key={a.value} onSelect={() => onTrigger(`aggregator:${a.value}`)}>
                        {a.label}
                    </DropdownMenuItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
