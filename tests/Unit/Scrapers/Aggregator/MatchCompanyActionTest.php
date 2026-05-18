<?php

declare(strict_types=1);

namespace Tests\Unit\Scrapers\Aggregator;

use App\Scrapers\Aggregator\CompanyKeyNormalizer;
use App\Scrapers\Aggregator\MatchCompanyAction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit tests on the Jaro-Winkler scorer + threshold boundary. The
 * Eloquent-side path lives in the feature test.
 */
final class MatchCompanyActionTest extends TestCase
{
    private MatchCompanyAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = new MatchCompanyAction(new CompanyKeyNormalizer());
    }

    #[Test]
    public function identical_strings_score_one(): void
    {
        $this->assertSame(1.0, $this->action->jaroWinkler('acme', 'acme'));
    }

    #[Test]
    public function empty_string_scores_zero(): void
    {
        $this->assertSame(0.0, $this->action->jaroWinkler('', 'acme'));
        $this->assertSame(0.0, $this->action->jaroWinkler('acme', ''));
    }

    #[Test]
    public function disjoint_strings_score_zero(): void
    {
        $this->assertSame(0.0, $this->action->jaroWinkler('xyz', 'qqq'));
    }

    /**
     * @param  float  $expectedMin  lower bound (inclusive); actual score should be above this
     */
    #[Test]
    #[DataProvider('similarPairs')]
    public function similar_pairs_score_above(string $a, string $b, float $expectedMin): void
    {
        $score = $this->action->jaroWinkler($a, $b);
        $this->assertGreaterThanOrEqual(
            $expectedMin,
            $score,
            "Jaro-Winkler('{$a}', '{$b}') = {$score}, expected ≥ {$expectedMin}",
        );
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: float}>
     */
    public static function similarPairs(): iterable
    {
        // Stripe vs Stripe.com — normalizer-stripped to stripe vs stripecom.
        yield 'stripe vs stripecom' => ['stripe', 'stripecom', 0.90];
        // Common typo.
        yield 'acme vs acmee' => ['acme', 'acmee', 0.90];
        // Shared prefix → Winkler bonus carries it.
        yield 'globex vs globextech' => ['globex', 'globextech', 0.85];
        // One-character difference at end.
        yield 'monzo vs monzos' => ['monzo', 'monzos', 0.95];
    }

    /**
     * @param  float  $expectedMax  upper bound (exclusive); actual score should be below this
     */
    #[Test]
    #[DataProvider('dissimilarPairs')]
    public function dissimilar_pairs_score_below(string $a, string $b, float $expectedMax): void
    {
        $score = $this->action->jaroWinkler($a, $b);
        $this->assertLessThan(
            $expectedMax,
            $score,
            "Jaro-Winkler('{$a}', '{$b}') = {$score}, expected < {$expectedMax}",
        );
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: float}>
     */
    public static function dissimilarPairs(): iterable
    {
        yield 'acme vs globex' => ['acme', 'globex', MatchCompanyAction::THRESHOLD];
        yield 'stripe vs square' => ['stripe', 'square', MatchCompanyAction::THRESHOLD];
        yield 'short vs different short' => ['ai', 'ml', MatchCompanyAction::THRESHOLD];
    }
}
