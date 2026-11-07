<?php

declare(strict_types=1);

namespace App\Signals\Funding;

/**
 * Finds the company that raised money in a funding headline, e.g. "Neela"
 * in "Cambridge biotech startup Neela raises £2.1m to turn waste into fuel".
 *
 * Takes the capitalised words right before a funding verb ("raises",
 * "secures", "lands", ...), stopping at the first lower-case word, a colon,
 * or a descriptor such as "Startup" or "Firm". Returns null when the
 * headline does not follow that shape ("Accel to lead a $5B round for X").
 */
final class FundedCompanyExtractor
{
    private const VERBS = 'raises|raised|raise|secures|secured|lands|landed|closes|closed|bags|nabs|snags|scores|grabs|gets|picks up|attracts|collects|wins';

    private const MAX_WORDS = 4;

    /**
     * Title-case words that describe a company rather than name it. Anything
     * up to and including the last of these is dropped ("AI Firm Replit").
     *
     * @var list<string>
     */
    private const DESCRIPTORS = [
        'startup', 'start-up', 'scaleup', 'scale-up', 'firm', 'company', 'unicorn', 'founders', 'co-founders',
        'founder', 'fintech', 'biotech', 'healthtech', 'insurtech', 'proptech', 'edtech', 'platform', 'maker',
        'developer', 'provider', 'specialist', 'exclusive', 'report', 'breaking', 'and', 'alumni', 'veterans',
    ];

    /**
     * Small words allowed inside a name ("Bank of Dave", "Titan OS").
     *
     * @var list<string>
     */
    private const CONNECTORS = ['of', 'de', 'la', 'and', '&', 'for', 'the'];

    public function extract(string $headline): ?string
    {
        if (preg_match('/^(?<before>.*?)\s+(?:'.self::VERBS.')\b/iu', $headline, $match) !== 1) {
            return null;
        }

        $words = preg_split('/\s+/u', trim($match['before'])) ?: [];
        $name = [];

        for ($i = count($words) - 1; $i >= 0; $i--) {
            $word = $words[$i];

            if (str_ends_with($word, ':') || str_ends_with($word, ',')) {
                break;
            }
            $isNamePart = preg_match('/^[\p{Lu}\p{N}]/u', $word) === 1;
            $isConnector = in_array(mb_strtolower($word), self::CONNECTORS, true) && $name !== [];
            if (! $isNamePart && ! $isConnector) {
                break;
            }
            array_unshift($name, $word);
        }

        // Drop everything up to the last descriptor word.
        for ($index = count($name) - 1; $index >= 0; $index--) {
            if (in_array(mb_strtolower(trim($name[$index], "'’\"“”")), self::DESCRIPTORS, true)) {
                $name = array_slice($name, $index + 1);
                break;
            }
        }

        // Leading connectors are not part of a name.
        while ($name !== [] && in_array(mb_strtolower($name[0]), self::CONNECTORS, true)) {
            array_shift($name);
        }

        if ($name === [] || count($name) > self::MAX_WORDS) {
            return null;
        }

        $result = trim(implode(' ', $name), " \t'’\"“”");
        $result = (string) preg_replace('/[\'’]s$/u', '', $result);

        return $result === '' ? null : $result;
    }
}
