<?php

declare(strict_types=1);

namespace App\Scrapers\Aggregator\Parser\Extractors;

use App\Scrapers\Aggregator\Parser\Contracts\Extractor;
use App\Scrapers\Aggregator\Parser\ExtractedField;
use App\Scrapers\Aggregator\Parser\ExtractionSource;
use App\Scrapers\Aggregator\Parser\SourceContext;

/**
 *   Himalayas : structured.employmentType[] (e.g. ["Full-Time"])
 *   Remotive  : structured.job_type (e.g. "full_time")
 *   Others    : body keywords
 *
 * Canonical values: full_time, part_time, contract, freelance, internship.
 */
final class EmploymentTypeExtractor implements Extractor
{
    public const FIELD = 'employment_type';

    public function field(): string
    {
        return self::FIELD;
    }

    public function extract(SourceContext $ctx): ExtractedField
    {
        $structured = $ctx->structured['employmentType'] ?? null;
        if (is_array($structured) && isset($structured[0]) && is_string($structured[0])) {
            return new ExtractedField(
                field: self::FIELD,
                value: $this->normalize($structured[0]),
                confidence: 0.9,
                source: ExtractionSource::EXPLICIT,
                evidence: 'structured.employmentType[0]',
            );
        }

        $jobType = $ctx->structured['job_type'] ?? null;
        if (is_string($jobType) && $jobType !== '') {
            return new ExtractedField(
                field: self::FIELD,
                value: $this->normalize($jobType),
                confidence: 0.9,
                source: ExtractionSource::EXPLICIT,
                evidence: 'structured.job_type',
            );
        }

        $title = (string) ($ctx->structured['title'] ?? $ctx->structured['position'] ?? '');
        $body = $ctx->bodyText ?? '';
        $haystack = trim($title."\n".$body);

        if ($haystack === '') {
            return ExtractedField::missing(self::FIELD);
        }

        return match (true) {
            preg_match('/\b(internship|intern\b)/i', $haystack) === 1 => $this->inferred('internship', $haystack),
            preg_match('/\b(part[\s\-]time)\b/i', $haystack) === 1 => $this->inferred('part_time', $haystack),
            preg_match('/\b(freelance|freelancer)\b/i', $haystack) === 1 => $this->inferred('freelance', $haystack),
            preg_match('/\b(contract(?:or)?|contracting)\b/i', $haystack) === 1 => $this->inferred('contract', $haystack),
            preg_match('/\b(full[\s\-]time)\b/i', $haystack) === 1 => $this->inferred('full_time', $haystack),
            default => ExtractedField::missing(self::FIELD),
        };
    }

    private function inferred(string $value, string $haystack): ExtractedField
    {
        return new ExtractedField(
            field: self::FIELD,
            value: $value,
            confidence: 0.7,
            source: ExtractionSource::INFERRED,
            evidence: 'matched in title/body',
        );
    }

    private function normalize(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = str_replace([' ', '-'], '_', $normalized);

        return match ($normalized) {
            'full_time', 'fulltime' => 'full_time',
            'part_time', 'parttime' => 'part_time',
            'contract', 'contractor', 'contracting' => 'contract',
            'freelance', 'freelancer' => 'freelance',
            'internship', 'intern' => 'internship',
            default => $normalized,
        };
    }
}
