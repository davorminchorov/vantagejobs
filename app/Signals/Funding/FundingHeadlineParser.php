<?php

declare(strict_types=1);

namespace App\Signals\Funding;

/**
 * Pulls the facts a reviewer needs out of a Google News funding headline:
 * the publisher (Google appends " - Publisher" to every title), the round
 * ("Series D", "Seed") and the amount raised.
 *
 * Amounts next to valuation wording ("valued at $4B", "$3 Billion
 * Valuation") are skipped so the parser reports the raise, not the
 * valuation. Only US dollar amounts fill `amount_usd`; euro, pound and
 * other-dollar figures ("$12 million NZD") are reported in `amount_label` only.
 */
final class FundingHeadlineParser
{
    private const PUBLISHER_MAX_LENGTH = 60;

    private const VALUATION_WINDOW = 30;

    private const MONEY_PATTERN = '/(?<currency>[$€£])\s?(?<number>\d{1,3}(?:,\d{3})+|\d+(?:\.\d+)?)(?:\s?(?<unit>billion|million|thousand|bn|mn|b|m|k)\b)?/iu';

    /**
     * @param  bool  $hasPublisherSuffix  Google News titles end in " - Publisher"; feed titles from the publisher itself do not.
     * @return array{headline: string, publisher: string|null, stage: string|null, amount_usd: int|null, amount_label: string|null}
     */
    public function parse(string $title, bool $hasPublisherSuffix = true): array
    {
        [$headline, $publisher] = $hasPublisherSuffix ? $this->splitPublisher(trim($title)) : [trim($title), null];
        $amount = $this->amount($headline);

        return [
            'headline' => $headline,
            'publisher' => $publisher,
            'stage' => $this->stage($headline),
            'amount_usd' => $amount !== null && $amount['currency'] === '$' ? $amount['value'] : null,
            'amount_label' => $amount !== null ? self::label($amount['currency'], $amount['value']) : null,
        ];
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    private function splitPublisher(string $title): array
    {
        $position = mb_strrpos($title, ' - ');
        if ($position === false) {
            return [$title, null];
        }

        $headline = trim(mb_substr($title, 0, $position));
        $publisher = trim(mb_substr($title, $position + 3));

        if ($headline === '' || $publisher === '' || mb_strlen($publisher) > self::PUBLISHER_MAX_LENGTH) {
            return [$title, null];
        }

        return [rtrim($headline, " -\u{2013}"), $publisher];
    }

    private function stage(string $headline): ?string
    {
        if (preg_match('/\bpre-?seed\b/i', $headline) === 1) {
            return 'Pre-seed';
        }
        if (preg_match('/\bseries\s+([a-h])\b/i', $headline, $match) === 1) {
            return 'Series '.strtoupper($match[1]);
        }
        if (preg_match('/\bseed\b/i', $headline) === 1) {
            return 'Seed';
        }

        return null;
    }

    /**
     * @return array{currency: string, value: int}|null
     */
    private function amount(string $headline): ?array
    {
        if (preg_match_all(self::MONEY_PATTERN, $headline, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === 0) {
            return null;
        }

        foreach ($matches as $index => $match) {
            [$text, $offset] = $match[0];
            $end = $offset + strlen($text);

            $previousEnd = $index > 0 ? $matches[$index - 1][0][1] + strlen($matches[$index - 1][0][0]) : 0;
            $before = substr($headline, max($previousEnd, $offset - self::VALUATION_WINDOW), $offset - max($previousEnd, $offset - self::VALUATION_WINDOW));

            $nextStart = isset($matches[$index + 1]) ? $matches[$index + 1][0][1] : strlen($headline);
            $after = substr($headline, $end, min($nextStart, $end + self::VALUATION_WINDOW) - $end);

            // "valued at $4B" / "valuing it at $20B" before the figure, or
            // "$3B valuation" / "$1B post-money valuation" right after it.
            if (preg_match('/valu/i', $before) === 1 || preg_match('/^[\s\w-]{0,20}?\bvaluation\b/i', $after) === 1) {
                continue;
            }

            $value = $this->value($match['number'][0], $match['unit'][0] ?? '');
            if ($value === null) {
                continue;
            }

            // "$12 million NZD": a dollar sign that is not US dollars.
            if ($match['currency'][0] === '$' && preg_match('/^\s*(NZD|AUD|CAD|SGD|HKD|MXN)\b/', $after, $code) === 1) {
                return ['currency' => $code[1].' ', 'value' => $value];
            }

            return ['currency' => $match['currency'][0], 'value' => $value];
        }

        return null;
    }

    private function value(string $number, string $unit): ?int
    {
        $base = (float) str_replace(',', '', $number);
        $multiplier = match (strtolower($unit)) {
            'billion', 'bn', 'b' => 1_000_000_000,
            'million', 'mn', 'm' => 1_000_000,
            'thousand', 'k' => 1_000,
            default => 1,
        };

        $value = (int) round($base * $multiplier);

        // A bare "$5" is never a funding round; it is a share price or noise.
        return $value >= 10_000 ? $value : null;
    }

    private static function label(string $currency, int $value): string
    {
        return match (true) {
            $value >= 1_000_000_000 => $currency.self::trimmed($value / 1_000_000_000).'B',
            $value >= 1_000_000 => $currency.self::trimmed($value / 1_000_000).'M',
            default => $currency.self::trimmed($value / 1_000).'k',
        };
    }

    private static function trimmed(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
