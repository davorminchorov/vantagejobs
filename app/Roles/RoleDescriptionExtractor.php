<?php

declare(strict_types=1);

namespace App\Roles;

use App\Models\Role;
use App\Scrapers\Aggregator\SourceContextFactory;
use Throwable;

/**
 * Builds a readable plain-text job description for a Role from whatever its
 * source stored in `roles.raw`. Sources that store no description (workday,
 * smartrecruiters, ashby, workable, rippling, bamboohr) yield null.
 */
final readonly class RoleDescriptionExtractor
{
    public const int LIMIT = 10_000;

    /**
     * Sources whose `raw` payload carries description text.
     *
     * @var list<string>
     */
    private const array TEXT_SOURCES = ['greenhouse', 'lever', 'teamtailor', 'recruitee', 'personio', 'pinpoint'];

    public function __construct(private SourceContextFactory $contextFactory) {}

    /**
     * Cheap check used by list pages: avoids building the text itself.
     * Does not load the aggregator listing; relies on the FK.
     */
    public function hasDescription(Role $role): bool
    {
        if ($role->aggregator_listing_id !== null) {
            return true;
        }

        $raw = $role->raw;
        if (! is_array($raw)) {
            return false;
        }

        return match ($role->source) {
            'greenhouse' => $this->filled($raw['content'] ?? null),
            'lever' => $this->filled($raw['descriptionPlain'] ?? null)
                || $this->filled($raw['lists'] ?? null)
                || $this->filled($raw['additionalPlain'] ?? null),
            'teamtailor' => $this->filled($raw['description'] ?? null),
            'recruitee' => $this->filled($raw['description'] ?? null) || $this->filled($raw['requirements'] ?? null),
            'personio' => $this->personioSections($raw) !== [],
            'pinpoint' => $this->filled($raw['description'] ?? null)
                || $this->filled($raw['key_responsibilities'] ?? null)
                || $this->filled($raw['skills_knowledge_expertise'] ?? null)
                || $this->filled($raw['benefits'] ?? null),
            default => false,
        };
    }

    public function for(Role $role): ?string
    {
        try {
            $text = $this->build($role);
        } catch (Throwable) {
            return null;
        }

        return $text === null ? null : $this->finish($text);
    }

    private function build(Role $role): ?string
    {
        if ($role->aggregator_listing_id !== null) {
            $listing = $role->aggregatorListing;
            if ($listing === null) {
                return null;
            }

            return $this->contextFactory->buildFor($listing)->bodyText;
        }

        $raw = $role->raw;
        if (! is_array($raw)) {
            return null;
        }

        return match ($role->source) {
            'greenhouse' => $this->html($raw['content'] ?? null),
            'lever' => $this->lever($raw),
            'teamtailor' => $this->html($raw['description'] ?? null),
            'recruitee' => $this->join([
                $this->html($raw['description'] ?? null),
                $this->html($raw['requirements'] ?? null),
            ]),
            'personio' => $this->personio($raw),
            'pinpoint' => $this->pinpoint($raw),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function lever(array $raw): ?string
    {
        $parts = [$this->plain($raw['descriptionPlain'] ?? null) ?? $this->html($raw['description'] ?? null)];

        $lists = $raw['lists'] ?? null;
        if (is_array($lists)) {
            foreach ($lists as $list) {
                if (! is_array($list)) {
                    continue;
                }
                $parts[] = $this->join([
                    $this->plain($list['text'] ?? null),
                    $this->html($list['content'] ?? null),
                ]);
            }
        }

        $parts[] = $this->plain($raw['additionalPlain'] ?? null) ?? $this->html($raw['additional'] ?? null);

        return $this->join($parts);
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return list<array{name: ?string, html: string}>
     */
    private function personioSections(array $raw): array
    {
        $descriptions = $raw['jobDescriptions'] ?? null;
        if (! is_array($descriptions)) {
            return [];
        }

        $items = array_key_exists('jobDescription', $descriptions) ? $descriptions['jobDescription'] : $descriptions;
        if (! is_array($items)) {
            return [];
        }
        if (isset($items['name']) || isset($items['value'])) {
            $items = [$items];
        }

        $sections = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $value = $item['value'] ?? null;
            $html = is_array($value)
                ? implode("\n", array_filter($value, static fn (mixed $v): bool => is_string($v) && trim($v) !== ''))
                : (is_string($value) ? $value : '');
            if (trim($html) === '') {
                continue;
            }
            $name = isset($item['name']) && is_string($item['name']) && trim($item['name']) !== '' ? trim($item['name']) : null;
            $sections[] = ['name' => $name, 'html' => $html];
        }

        return $sections;
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function personio(array $raw): ?string
    {
        return $this->join(array_map(
            fn (array $section): ?string => $this->join([$section['name'], $this->html($section['html'])]),
            $this->personioSections($raw),
        ));
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function pinpoint(array $raw): ?string
    {
        $parts = [$this->html($raw['description'] ?? null)];

        foreach (['key_responsibilities', 'skills_knowledge_expertise', 'benefits'] as $key) {
            $body = $this->html($raw[$key] ?? null);
            if ($body === null) {
                continue;
            }
            $header = $this->plain($raw[$key.'_header'] ?? null);
            $parts[] = $this->join([$header, $body]);
        }

        return $this->join($parts);
    }

    /**
     * @param  array<int, ?string>  $parts
     */
    private function join(array $parts): ?string
    {
        $parts = array_values(array_filter($parts, static fn (?string $p): bool => $p !== null && trim($p) !== ''));

        return $parts === [] ? null : implode("\n\n", $parts);
    }

    private function filled(mixed $value): bool
    {
        if (is_string($value)) {
            return trim($value) !== '';
        }

        return is_array($value) && $value !== [];
    }

    private function plain(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function html(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        // Greenhouse entity-encodes its markup (&lt;p&gt;), so decode first
        // only when no real tags are present.
        $html = preg_match('/<[a-z!\/]/i', $value) === 1
            ? $value
            : html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $html = (string) preg_replace('#<!--.*?-->#s', '', $html);
        $html = (string) preg_replace('#<(script|style)\b[^>]*>.*?</\1\s*>#is', '', $html);
        $html = (string) preg_replace('#<\s*li\b[^>]*>#i', "\n- ", $html);
        $html = (string) preg_replace('#<\s*br\s*/?\s*>#i', "\n", $html);
        $html = (string) preg_replace('#</?\s*(p|div|h[1-6]|ul|ol|tr|table|section|blockquote)\b[^>]*>#i', "\n", $html);
        $html = (string) preg_replace('#</\s*li\s*>#i', "\n", $html);

        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{00A0}", ' ', $text);
        $text = (string) preg_replace('/[ \t]+/', ' ', $text);
        $text = (string) preg_replace('/ *\n */', "\n", $text);
        $text = (string) preg_replace('/^-[ \t]*\n+(?=[^\s-])/m', '- ', $text);
        $text = (string) preg_replace('/^-[ \t]*$/m', '', $text);
        $text = (string) preg_replace('/^(- [^\n]*)\n{2,}(?=- )/m', "$1\n", $text);

        $text = trim($text);

        return $text === '' ? null : $text;
    }

    private function finish(string $text): ?string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{00A0}", ' ', $text);
        $text = trim((string) preg_replace("/\n{3,}/", "\n\n", trim($text)));
        if ($text === '') {
            return null;
        }

        if (mb_strlen($text) > self::LIMIT) {
            return rtrim(mb_substr($text, 0, self::LIMIT)).'…';
        }

        return $text;
    }
}
