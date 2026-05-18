<?php

declare(strict_types=1);

namespace Tests\Feature\Scrapers\Aggregator\Parser;

use App\Scrapers\Aggregator\HackerNews\HackerNewsContextBuilder;
use App\Scrapers\Aggregator\Parser\Extractors\ApplyMethodExtractor;
use App\Scrapers\Aggregator\Parser\Extractors\CompanyNameExtractor;
use App\Scrapers\Aggregator\Parser\Extractors\CompensationExtractor;
use App\Scrapers\Aggregator\Parser\Extractors\DisciplineExtractor;
use App\Scrapers\Aggregator\Parser\Extractors\EmploymentTypeExtractor;
use App\Scrapers\Aggregator\Parser\Extractors\LocationExtractor;
use App\Scrapers\Aggregator\Parser\Extractors\RegionExtractor;
use App\Scrapers\Aggregator\Parser\Extractors\RemoteExtractor;
use App\Scrapers\Aggregator\Parser\Extractors\RoleTitleExtractor;
use App\Scrapers\Aggregator\Parser\Extractors\SeniorityExtractor;
use App\Scrapers\Aggregator\Parser\Extractors\StackTagsExtractor;
use App\Scrapers\Aggregator\Parser\Extractors\VisaSponsorshipExtractor;
use App\Scrapers\Aggregator\Parser\ParsedListing;
use App\Scrapers\Aggregator\Parser\ParserPipeline;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Drives the full extractor pipeline against curated HackerNews fixture
 * postings (tests/Fixtures/parsers/hackernews/*.json).
 *
 * Each fixture's `expected` block is a sparse map: keys name a ParsedListing
 * field (with dot notation for compound fields like `compensation.min_usd`),
 * and the suffix dictates the assertion:
 *
 *   foo                    — equality
 *   foo_include => [...]   — list value contains every item
 *   foo_contains => "..."  — string value contains the substring
 *
 * Fixtures don't need to cover every field — they only assert what the
 * posting clearly conveys. This keeps fixtures editable as the parser
 * evolves without spurious failures.
 */
final class HackerNewsParserFixturesTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $fixture
     */
    #[Test]
    #[DataProvider('fixtures')]
    public function parses_fixture_to_expected_shape(string $name, array $fixture): void
    {
        $context = (new HackerNewsContextBuilder())->build([
            'id' => $fixture['comment_id'],
            'author' => $fixture['author'] ?? '',
            'text' => $fixture['text'],
            'created_at' => $fixture['created_at'] ?? null,
        ]);

        $listing = $this->pipeline()->parse($context);

        /** @var array<string, mixed> $expected */
        $expected = $fixture['expected'] ?? [];
        foreach ($expected as $key => $value) {
            $this->assertExpectation($name, $listing, (string) $key, $value);
        }
    }

    /**
     * @return iterable<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function fixtures(): iterable
    {
        $dir = __DIR__.'/../../../../Fixtures/parsers/hackernews';
        $files = glob($dir.'/*.json');
        if ($files === false) {
            return;
        }
        sort($files);

        foreach ($files as $path) {
            $name = basename($path, '.json');
            $raw = file_get_contents($path);
            self::assertNotFalse($raw, "Failed to read fixture {$path}");

            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

            // Skip step-5 "save-as-fixture" exports: those carry a top-level
            // `source` + `raw` + `structured` shape rather than the curated
            // {comment_id, text, expected} shape this test drives.
            if (! isset($decoded['comment_id']) || ! isset($decoded['text'])) {
                continue;
            }

            yield $name => [$name, $decoded];
        }
    }

    private function pipeline(): ParserPipeline
    {
        return new ParserPipeline([
            new CompanyNameExtractor(),
            new RoleTitleExtractor(),
            new DisciplineExtractor(),
            new SeniorityExtractor(),
            new LocationExtractor(),
            new RegionExtractor(),
            new RemoteExtractor(),
            new CompensationExtractor(),
            new StackTagsExtractor(),
            new EmploymentTypeExtractor(),
            new VisaSponsorshipExtractor(),
            new ApplyMethodExtractor(),
        ]);
    }

    private function assertExpectation(string $fixture, ParsedListing $listing, string $key, mixed $expected): void
    {
        if (str_ends_with($key, '_include')) {
            $field = substr($key, 0, -strlen('_include'));
            $actual = $this->resolveValue($listing, $field);
            self::assertIsArray($actual, "[{$fixture}] expected {$field} to be an array");
            self::assertIsArray($expected);
            foreach ($expected as $needle) {
                self::assertContains($needle, $actual, "[{$fixture}] expected {$field} to include '{$needle}', got: ".json_encode($actual));
            }

            return;
        }

        if (str_ends_with($key, '_contains')) {
            $field = substr($key, 0, -strlen('_contains'));
            $actual = $this->resolveValue($listing, $field);
            self::assertIsString($actual, "[{$fixture}] expected {$field} to be a string");
            self::assertIsString($expected);
            self::assertStringContainsString($expected, $actual, "[{$fixture}] expected {$field} to contain '{$expected}', got: '{$actual}'");

            return;
        }

        $actual = $this->resolveValue($listing, $key);
        self::assertSame($expected, $actual, "[{$fixture}] expected {$key} = ".json_encode($expected).', got '.json_encode($actual));
    }

    private function resolveValue(ParsedListing $listing, string $path): mixed
    {
        $parts = explode('.', $path);
        $field = array_shift($parts);
        $value = $listing->value($field);

        foreach ($parts as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }
}
