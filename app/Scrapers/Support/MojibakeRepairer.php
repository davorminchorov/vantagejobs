<?php

declare(strict_types=1);

namespace App\Scrapers\Support;

/**
 * Repairs UTF-8 payloads that upstream aggregator APIs (confirmed on
 * RemoteOK, occasionally Himalayas) serve double-encoded — the API reads
 * its own UTF-8-encoded text as ISO-8859-1 and re-encodes that to UTF-8
 * before serving, producing e.g. "MÃ©xico" instead of "México".
 *
 * Repair strategy: a double-encoded string's non-ASCII characters are all
 * single UTF-8-encoded Latin-1 code points (U+0080-U+00FF) — the upstream
 * mis-decode step can't produce anything outside that range. Reinterpreting
 * those code points as raw bytes and decoding the result as UTF-8 recovers
 * the original text whenever the mis-encoding actually happened.
 *
 * Guard against over-repairing genuine Latin-script text (e.g. "Québec",
 * which also only uses code points <= 0xFF): reinterpreting a string's code
 * points as bytes only forms valid UTF-8 when they happen to encode a real
 * lead-byte/continuation-byte pattern. A lone accented letter surrounded by
 * plain ASCII almost never does — its byte, in isolation, is not valid
 * UTF-8. So the check is inherently one-directional: it can accidentally
 * miss a rare double-encoded string, never wrongly mangle a genuine one. On
 * any failure (not valid UTF-8, or no non-ASCII to begin with) the original
 * string is returned unchanged — the same fallback that protects
 * upstream-truncated multi-byte sequences.
 */
final class MojibakeRepairer
{
    public static function repair(string $value): string
    {
        if ($value === '' || preg_match('/[^\x00-\x7F]/u', $value) !== 1) {
            return $value;
        }

        $chars = mb_str_split($value, 1, 'UTF-8');
        $bytes = '';

        foreach ($chars as $char) {
            $codepoint = mb_ord($char, 'UTF-8');
            if ($codepoint === false || $codepoint > 0xFF) {
                return $value;
            }
            $bytes .= chr($codepoint);
        }

        if (! mb_check_encoding($bytes, 'UTF-8')) {
            return $value;
        }

        return $bytes;
    }

    /**
     * Applies repair() to every string value in an array payload, recursing
     * into nested arrays. Non-string, non-array values pass through as-is.
     *
     * @param  array<mixed>  $payload
     * @return array<mixed>
     */
    public static function repairArray(array $payload): array
    {
        $result = [];

        foreach ($payload as $key => $value) {
            if (is_string($value)) {
                $result[$key] = self::repair($value);
            } elseif (is_array($value)) {
                $result[$key] = self::repairArray($value);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
