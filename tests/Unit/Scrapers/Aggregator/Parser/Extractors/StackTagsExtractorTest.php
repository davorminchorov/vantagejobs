<?php

declare(strict_types=1);

namespace Tests\Unit\Scrapers\Aggregator\Parser\Extractors;

use App\Enums\AggregatorSourceType;
use App\Scrapers\Aggregator\Parser\ExtractionSource;
use App\Scrapers\Aggregator\Parser\Extractors\StackTagsExtractor;
use App\Scrapers\Aggregator\Parser\SourceContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Boots the framework only to resolve base_path() for the dictionary file.
 * No database, no app state beyond container init.
 */
final class StackTagsExtractorTest extends TestCase
{
    #[Test]
    public function field_name_is_stack_tags(): void
    {
        $this->assertSame('stack_tags', (new StackTagsExtractor())->field());
    }

    #[Test]
    public function nothing_found_returns_missing(): void
    {
        $field = $this->extract(['title' => 'Senior Marketing Manager'], bodyText: 'Generic copy, no tech mentioned anywhere.');

        $this->assertTrue($field->isMissing());
    }

    /* --------------------------------------------------------------
     | Structured-tag channel (Remotive / RemoteOK style)
     * -------------------------------------------------------------- */

    #[Test]
    public function structured_tags_resolve_via_canonical(): void
    {
        $field = $this->extract(['tags' => ['python', 'django', 'postgres']]);

        $this->assertSame(['django', 'postgres', 'python'], $field->value);
        $this->assertSame(ExtractionSource::EXPLICIT, $field->source);
        $this->assertSame(0.95, $field->confidence);
    }

    #[Test]
    public function structured_tags_resolve_via_alias(): void
    {
        $field = $this->extract(['tags' => ['py', 'reactjs', 'ts', 'k8s']]);

        $this->assertSame(['kubernetes', 'python', 'react', 'typescript'], $field->value);
    }

    #[Test]
    public function unknown_structured_tags_are_ignored(): void
    {
        $field = $this->extract(['tags' => ['cobol', 'tabletops', 'python']]);

        $this->assertSame(['python'], $field->value);
    }

    #[Test]
    public function non_string_structured_tags_are_ignored(): void
    {
        $field = $this->extract(['tags' => ['python', null, 42, 'react']]);

        $this->assertSame(['python', 'react'], $field->value);
    }

    /* --------------------------------------------------------------
     | Body channel — default-pattern matches
     * -------------------------------------------------------------- */

    #[Test]
    public function body_matches_canonical_with_word_boundary(): void
    {
        $field = $this->extract([], bodyText: 'We use Python and PostgreSQL daily.');

        $this->assertSame(['postgres', 'python'], $field->value);
        $this->assertSame(ExtractionSource::INFERRED, $field->source);
        $this->assertSame(0.6, $field->confidence);
    }

    #[Test]
    public function body_does_not_match_js_inside_json(): void
    {
        $field = $this->extract([], bodyText: 'We send JSON payloads over HTTPS to our backend services.');

        // 'json' must not register js; 'js' is not a standalone token here.
        $value = $field->isMissing() ? [] : $field->value;
        $this->assertNotContains('javascript', $value);
    }

    #[Test]
    public function body_does_not_match_ts_inside_tags(): void
    {
        $field = $this->extract([], bodyText: 'We have many tags and stats in our system, no frontend involved.');

        $value = $field->isMissing() ? [] : $field->value;
        $this->assertNotContains('typescript', $value);
    }

    #[Test]
    public function js_alias_matches_when_standalone(): void
    {
        $field = $this->extract([], bodyText: 'We write JS on the frontend and Go on the backend with goroutines.');

        $this->assertContains('javascript', $field->value);
    }

    #[Test]
    public function title_field_is_searched(): void
    {
        $field = $this->extract(['title' => 'Senior Rust Engineer']);

        $this->assertSame(['rust'], $field->value);
    }

    /* --------------------------------------------------------------
     | False-positive traps — short / ambiguous tokens
     * -------------------------------------------------------------- */

    #[Test]
    public function go_is_dropped_in_english_prose_without_context(): void
    {
        $field = $this->extract([], bodyText: 'We want you to go to market with us. Ready to go?');

        $value = $field->isMissing() ? [] : $field->value;
        $this->assertNotContains('go', $value);
    }

    #[Test]
    public function go_matches_when_co_occurring_with_a_context_cue(): void
    {
        $field = $this->extract([], bodyText: 'Go services orchestrated with Kubernetes and gRPC.');

        $this->assertContains('go', $field->value);
        $this->assertContains('kubernetes', $field->value);
        $this->assertContains('grpc', $field->value);
    }

    #[Test]
    public function golang_alias_matches_via_its_own_word(): void
    {
        // 'golang' itself is one of the context cues so this passes.
        $field = $this->extract([], bodyText: 'Senior Golang Engineer wanted.');

        $this->assertContains('go', $field->value);
    }

    #[Test]
    public function r_is_dropped_in_english_prose(): void
    {
        $field = $this->extract([], bodyText: 'Are you ready? R&D budgets are growing rapidly.');

        $value = $field->isMissing() ? [] : $field->value;
        $this->assertNotContains('r', $value);
    }

    #[Test]
    public function r_matches_with_data_science_context(): void
    {
        $field = $this->extract([], bodyText: 'Analytics in R via tidyverse and ggplot.');

        $this->assertContains('r', $field->value);
    }

    #[Test]
    public function c_is_dropped_in_english_prose(): void
    {
        $field = $this->extract([], bodyText: 'You will work on a backend in production with the team.');

        $value = $field->isMissing() ? [] : $field->value;
        $this->assertNotContains('c', $value);
    }

    #[Test]
    public function c_matches_with_systems_context(): void
    {
        $field = $this->extract([], bodyText: 'Embedded C, built with gcc on a Linux kernel.');

        $this->assertContains('c', $field->value);
    }

    #[Test]
    public function d_is_dropped_without_dlang_context(): void
    {
        $field = $this->extract([], bodyText: 'D-Day approaches and we need a D-grade engineer apparently.');

        $value = $field->isMissing() ? [] : $field->value;
        $this->assertNotContains('d', $value);
    }

    #[Test]
    public function d_matches_with_dlang_context(): void
    {
        $field = $this->extract([], bodyText: 'D language work using phobos. Familiarity with dlang preferred.');

        $this->assertContains('d', $field->value);
    }

    #[Test]
    public function cplusplus_matches_via_explicit_pattern(): void
    {
        $field = $this->extract([], bodyText: 'You will write C++ on top of LLVM.');

        $this->assertContains('c++', $field->value);
    }

    #[Test]
    public function cplusplus_via_cpp_alias_in_body(): void
    {
        $field = $this->extract([], bodyText: 'High-perf cpp services for trading systems.');

        $this->assertContains('c++', $field->value);
    }

    #[Test]
    public function csharp_matches_via_pound_form_and_word_form(): void
    {
        $a = $this->extract([], bodyText: 'Senior C# Developer.');
        $b = $this->extract([], bodyText: 'We write CSharp on the backend.');

        $this->assertContains('c#', $a->value);
        $this->assertContains('c#', $b->value);
    }

    #[Test]
    public function fsharp_matches_via_pound_form(): void
    {
        $field = $this->extract([], bodyText: 'F# on .NET, functional shop.');

        $this->assertContains('f#', $field->value);
    }

    /* --------------------------------------------------------------
     | Merge across channels
     * -------------------------------------------------------------- */

    #[Test]
    public function mixed_structured_and_body_yields_explicit_dot_eight_five(): void
    {
        $field = $this->extract(
            ['tags' => ['python']],
            bodyText: 'Plus a sprinkle of Rust for the perf-critical paths.',
        );

        $this->assertSame(['python', 'rust'], $field->value);
        $this->assertSame(ExtractionSource::EXPLICIT, $field->source);
        $this->assertSame(0.85, $field->confidence);
    }

    #[Test]
    public function duplicates_across_channels_collapse_to_one(): void
    {
        $field = $this->extract(
            ['tags' => ['python']],
            bodyText: 'Python and Django.',
        );

        $this->assertSame(['django', 'python'], $field->value);
    }

    #[Test]
    public function output_is_sorted_alphabetically(): void
    {
        $field = $this->extract(['tags' => ['typescript', 'react', 'postgres', 'aws']]);

        $this->assertSame(['aws', 'postgres', 'react', 'typescript'], $field->value);
    }

    #[Test]
    public function evidence_records_channel_counts(): void
    {
        $field = $this->extract(
            ['tags' => ['python', 'django']],
            bodyText: 'Plus React on the frontend.',
        );

        $this->assertSame('structured:2 body:1', $field->evidence);
    }

    /* --------------------------------------------------------------
     | Format edges
     * -------------------------------------------------------------- */

    #[Test]
    public function case_insensitive_body_matching(): void
    {
        $field = $this->extract([], bodyText: 'PYTHON, Rust, kubernetes — full stack.');

        $this->assertSame(['kubernetes', 'python', 'rust'], $field->value);
    }

    #[Test]
    public function multi_word_alias_matches_in_structured(): void
    {
        $field = $this->extract(['tags' => ['react native']]);

        $this->assertSame(['react-native'], $field->value);
    }

    #[Test]
    public function spring_boot_alias_matches_in_body(): void
    {
        $field = $this->extract([], bodyText: 'Java microservices on Spring Boot.');

        $this->assertContains('spring', $field->value);
        $this->assertContains('java', $field->value);
    }

    #[Test]
    public function tailwind_canonical_matches_via_tailwindcss_alias(): void
    {
        $field = $this->extract([], bodyText: 'Frontend in Vue with TailwindCSS.');

        $this->assertContains('tailwind', $field->value);
        $this->assertContains('vue', $field->value);
    }

    #[Test]
    public function dictionary_override_isolates_tests(): void
    {
        $extractor = new StackTagsExtractor([
            'banana' => ['aliases' => ['nana'], 'category' => 'fruit'],
        ]);

        $field = $extractor->extract(new SourceContext(
            sourceType: AggregatorSourceType::REMOTIVE,
            structured: ['tags' => ['nana']],
        ));

        $this->assertSame(['banana'], $field->value);
    }

    /**
     * @param  array<string, mixed>  $structured
     */
    private function extract(array $structured, ?string $bodyText = null): \App\Scrapers\Aggregator\Parser\ExtractedField
    {
        return (new StackTagsExtractor())->extract(new SourceContext(
            sourceType: AggregatorSourceType::REMOTIVE,
            structured: $structured,
            bodyText: $bodyText,
        ));
    }
}
