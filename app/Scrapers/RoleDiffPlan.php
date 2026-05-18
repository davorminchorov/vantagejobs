<?php

declare(strict_types=1);

namespace App\Scrapers;

/**
 * Pure result of comparing a fetched role list against the existing rows
 * for one (company, source) pair. Apply this via RoleDiffer::apply().
 */
final readonly class RoleDiffPlan
{
    /**
     * @param  list<ParsedRole>  $toInsert  roles to insert (with NEW_ROLE event)
     * @param  list<int>  $toReopen  role ids to flip is_open back to true (with ROLE_REOPENED event)
     * @param  list<int>  $toClose  role ids to mark is_open=false (with ROLE_CLOSED event)
     * @param  list<array{id: int, parsed: ParsedRole}>  $toTouch  still-open roles to refresh last_seen_at on
     */
    public function __construct(
        public array $toInsert,
        public array $toReopen,
        public array $toClose,
        public array $toTouch,
    ) {}

    public function isNoop(): bool
    {
        return $this->toInsert === []
            && $this->toReopen === []
            && $this->toClose === []
            && $this->toTouch === [];
    }
}
