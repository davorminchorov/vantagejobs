<?php

declare(strict_types=1);

namespace App\Outreach;

/**
 * Mustache-style {{var}} substitution over a fixed variable map.
 *
 * Hand-rolled with str_replace (no templating dependency). Unknown
 * {{tokens}} are intentionally left verbatim so the user can spot and
 * fill them by hand at compose time.
 */
final readonly class TemplateRenderer
{
    /**
     * Supported template variables. Anything outside this set is left
     * untouched in the rendered output.
     *
     * @var list<string>
     */
    public const array VARIABLES = [
        'contact_first_name',
        'contact_last_name',
        'company',
        'role',
    ];

    /**
     * Substitute every known {{token}} in the body with its mapped value.
     * Values are coerced to strings; null/missing values render as empty.
     *
     * @param  array<string, string|null>  $variables
     */
    public function render(string $body, array $variables): string
    {
        $search = [];
        $replace = [];

        foreach (self::VARIABLES as $name) {
            $search[] = '{{'.$name.'}}';
            $replace[] = (string) ($variables[$name] ?? '');
        }

        return str_replace($search, $replace, $body);
    }
}
