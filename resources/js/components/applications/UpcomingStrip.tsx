import { Link } from '@inertiajs/react';

import { formatEventShort } from './helpers';
import type { AgendaEvent } from './types';

interface UpcomingStripProps {
    events: AgendaEvent[];
    onOpenApplication: (roleId: number) => void;
}

/** The next few events, shown above the application list. */
export function UpcomingStrip({ events, onOpenApplication }: UpcomingStripProps) {
    if (events.length === 0) {
        return null;
    }

    return (
        <section aria-label="Upcoming events" className="flex flex-col gap-1">
            <h2 className="text-sm font-medium text-muted-foreground">Upcoming</h2>
            <ul role="list" className="flex flex-col divide-y divide-border border-y border-border">
                {events.slice(0, 5).map((event) => {
                    const owner = event.owner;
                    return (
                        <li key={event.id} className="flex flex-col gap-0.5 py-2 sm:flex-row sm:items-baseline sm:gap-3">
                            <span className="shrink-0 text-base text-muted-foreground tabular-nums sm:w-36 sm:text-sm">
                                {formatEventShort(event.starts_at, event.timezone)}
                            </span>
                            <span className="min-w-0 flex-1 text-base sm:text-sm">
                                <span className="font-medium">{event.title}</span>
                                {owner ? (
                                    <>
                                        <span className="text-muted-foreground"> for </span>
                                        {owner.role_id !== null ? (
                                            <button
                                                type="button"
                                                onClick={() => onOpenApplication(owner.role_id as number)}
                                                className="text-left text-primary hover:underline"
                                            >
                                                {owner.label}
                                            </button>
                                        ) : owner.href ? (
                                            <Link href={owner.href} className="text-primary hover:underline">
                                                {owner.label}
                                            </Link>
                                        ) : (
                                            <span className="text-muted-foreground">{owner.label}</span>
                                        )}
                                    </>
                                ) : null}
                            </span>
                        </li>
                    );
                })}
            </ul>
        </section>
    );
}
