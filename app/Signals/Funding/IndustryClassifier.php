<?php

declare(strict_types=1);

namespace App\Signals\Funding;

/**
 * Maps a funding article's category tags (and, when no tag matches, its
 * headline and summary) onto a fixed list of industries, then decides
 * whether the company is a software company.
 *
 * Feed tags mix industries with investor names, places and section names
 * ("iNovia Capital", "Cambridge", "In Brief"); only tags that contain an
 * industry keyword count, so the noise is ignored.
 *
 * Software: at least one software industry and no deep-tech one. Deep
 * tech: a deep-tech industry and no software industry other than AI, which
 * news sites tag on almost everything. Anything else is unclear (null).
 */
final class IndustryClassifier
{
    private const KIND_SOFTWARE = 'software';

    private const KIND_DEEP_TECH = 'deep_tech';

    private const KIND_NEUTRAL = 'neutral';

    /**
     * @var array<string, array{label: string, kind: string, keywords: list<string>}>
     */
    public const INDUSTRIES = [
        'ai' => ['label' => 'AI', 'kind' => self::KIND_SOFTWARE, 'keywords' => ['ai', 'artificial intelligence', 'machine learning', 'generative ai', 'genai', 'llm', 'ai agents']],
        'devtools_saas' => ['label' => 'SaaS & developer tools', 'kind' => self::KIND_SOFTWARE, 'keywords' => ['saas', 'developer tools', 'devtools', 'cloud computing', 'enterprise software', 'software', 'open source', 'productivity', 'data infrastructure', 'no-code', 'api']],
        'fintech' => ['label' => 'Fintech', 'kind' => self::KIND_SOFTWARE, 'keywords' => ['fintech', 'payments', 'banking', 'insurtech', 'insurance', 'crypto', 'web3', 'blockchain', 'lending', 'sme finance', 'wealthtech', 'accounting']],
        'security' => ['label' => 'Security', 'kind' => self::KIND_SOFTWARE, 'keywords' => ['cybersecurity', 'security', 'identity', 'privacy', 'fraud']],
        'hr_work' => ['label' => 'HR & work', 'kind' => self::KIND_SOFTWARE, 'keywords' => ['hr tech', 'hrtech', 'recruitment', 'recruiting', 'future of work']],
        'education' => ['label' => 'Education', 'kind' => self::KIND_SOFTWARE, 'keywords' => ['edtech', 'education']],
        'proptech' => ['label' => 'Property', 'kind' => self::KIND_SOFTWARE, 'keywords' => ['proptech', 'real estate', 'property']],
        'health' => ['label' => 'Health tech', 'kind' => self::KIND_NEUTRAL, 'keywords' => ['health tech', 'healthtech', 'digital health', 'healthcare', 'mental health', 'health']],
        'commerce_consumer' => ['label' => 'Commerce & consumer', 'kind' => self::KIND_NEUTRAL, 'keywords' => ['e-commerce', 'ecommerce', 'retail', 'consumer', 'marketplace', 'gaming', 'games', 'foodtech', 'media', 'entertainment', 'food', 'travel', 'tvs']],
        'biotech' => ['label' => 'Biotech & life sciences', 'kind' => self::KIND_DEEP_TECH, 'keywords' => ['biotech', 'life sciences', 'pharma', 'drug discovery', 'therapeutics', 'medtech', 'medical devices', 'genomics']],
        'climate_energy' => ['label' => 'Climate & energy', 'kind' => self::KIND_DEEP_TECH, 'keywords' => ['climate', 'climate tech', 'cleantech', 'sustainability', 'energy', 'renewable', 'renewables', 'battery', 'batteries', 'carbon', 'aviation fuel', 'greentech', 'power converters']],
        'defence_space' => ['label' => 'Defence & space', 'kind' => self::KIND_DEEP_TECH, 'keywords' => ['defence', 'defense', 'defencetech', 'defense tech', 'space', 'space tech', 'spacetech', 'aerospace', 'satellite', 'satellites', 'drones']],
        'robotics_hardware' => ['label' => 'Robotics & hardware', 'kind' => self::KIND_DEEP_TECH, 'keywords' => ['robotics', 'robots', 'hardware', 'semiconductors', 'semiconductor', 'chips', 'manufacturing', 'iot', 'physical ai', '3d printing', 'quantum']],
        'mobility' => ['label' => 'Mobility', 'kind' => self::KIND_DEEP_TECH, 'keywords' => ['transportation', 'mobility', 'automotive', 'autonomous vehicles', 'electric vehicles', 'electric trucks', 'ev charging', 'logistics']],
    ];

    /**
     * @param  list<string>  $categories  Feed tags for the article.
     * @return array{industries: list<string>, is_software: bool|null}
     */
    public function classify(array $categories, string $headline, ?string $summary): array
    {
        $industries = $this->match($categories);
        if ($industries === []) {
            $industries = $this->match([$headline, (string) $summary]);
        }

        return ['industries' => $industries, 'is_software' => $this->isSoftware($industries)];
    }

    public static function label(string $industry): string
    {
        return self::INDUSTRIES[$industry]['label'] ?? $industry;
    }

    /**
     * @param  list<string>  $texts
     * @return list<string>
     */
    private function match(array $texts): array
    {
        $found = [];
        foreach (self::INDUSTRIES as $key => $industry) {
            foreach ($industry['keywords'] as $keyword) {
                $pattern = '/(?<![\p{L}\p{N}])'.preg_quote($keyword, '/').'(?![\p{L}\p{N}])/iu';
                foreach ($texts as $text) {
                    if (preg_match($pattern, $text) === 1) {
                        $found[] = $key;
                        continue 3;
                    }
                }
            }
        }

        return $found;
    }

    /**
     * @param  list<string>  $industries
     */
    private function isSoftware(array $industries): ?bool
    {
        $kinds = array_map(static fn (string $key): string => self::INDUSTRIES[$key]['kind'], $industries);
        $software = array_values(array_filter($industries, static fn (string $key): bool => self::INDUSTRIES[$key]['kind'] === self::KIND_SOFTWARE));
        $hasDeepTech = in_array(self::KIND_DEEP_TECH, $kinds, true);

        if ($hasDeepTech) {
            return array_diff($software, ['ai']) === [] ? false : null;
        }

        return $software === [] ? null : true;
    }
}
