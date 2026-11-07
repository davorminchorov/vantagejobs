<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Escapes text for Markdown mail templates. Role titles, company names and
 * event titles can come from scraped job boards, so a value like
 * "Engineer](https://example.com)" must not become a link or break the
 * surrounding Markdown. Blade's {{ }} still HTML-escapes the result.
 */
final class MarkdownText
{
    private const string SPECIAL = '\\`*_{}[]()<>#+-.!|~';

    public static function escape(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        $text = (string) preg_replace('/\s+/u', ' ', $text);

        return addcslashes($text, self::SPECIAL);
    }
}
