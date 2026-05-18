<?php

declare(strict_types=1);

namespace App\Scrapers;

use App\Enums\WorkplaceType;
use Carbon\CarbonImmutable;

/**
 * Source-agnostic shape of a single fetched role.
 *
 * Each ATS scraper translates its raw response into a list of these and
 * hands the list to RoleDiffer. The differ does not know or care which
 * source produced the data beyond the AtsType passed alongside.
 */
final readonly class ParsedRole
{
    /**
     * @param  array<string, mixed>|null  $raw  source-specific payload retained for Phase 3 parser
     */
    public function __construct(
        public string $externalId,
        public string $title,
        public ?string $url = null,
        public ?string $location = null,
        public ?bool $isRemote = null,
        public WorkplaceType $workplaceType = WorkplaceType::UNSPECIFIED,
        public ?string $department = null,
        public ?string $team = null,
        public ?int $compMin = null,
        public ?int $compMax = null,
        public ?string $compCurrency = null,
        public ?string $compInterval = null,
        public ?array $raw = null,
        public ?CarbonImmutable $postedAt = null,
    ) {}
}
