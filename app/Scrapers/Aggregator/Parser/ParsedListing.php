<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\Parser;

/**
 * Result of running the ParserPipeline against one SourceContext.
 *
 * Holds the winning ExtractedField per field name. Scrapers persisting a
 * listing pull from these to populate the denormalized columns on
 * aggregator_listings (parsed_company_name, has_comp, stack_tags, etc.)
 * and toArray() into the `parsed` jsonb column.
 *
 * Field names are stable identifiers chosen by the extractors themselves;
 * canonical set used by Phase 2 extractors:
 *   company_name, role_title, discipline, seniority,
 *   location, region, remote_type, remote_timezone, remote_geo,
 *   comp_min, comp_max, comp_currency, comp_interval, comp_equity,
 *   comp_min_usd, comp_max_usd,
 *   stack_tags, employment_type, visa_sponsorship, apply_method.
 */
final readonly class ParsedListing
{
    /**
     * @param  array<string, ExtractedField>  $fields
     */
    public function __construct(private array $fields) {}

    public function get(string $field): ?ExtractedField
    {
        return $this->fields[$field] ?? null;
    }

    public function value(string $field): mixed
    {
        return $this->fields[$field]?->value;
    }

    public function has(string $field): bool
    {
        return isset($this->fields[$field]) && ! $this->fields[$field]->isMissing();
    }

    /**
     * @return array<string, ExtractedField>
     */
    public function all(): array
    {
        return $this->fields;
    }

    /**
     * Serializable form for the `parsed` jsonb column.
     *
     * @return array<string, array{value: mixed, confidence: float, source: string, evidence: ?string}>
     */
    public function toArray(): array
    {
        $out = [];
        foreach ($this->fields as $name => $field) {
            $out[$name] = $field->toArray();
        }

        return $out;
    }
}
