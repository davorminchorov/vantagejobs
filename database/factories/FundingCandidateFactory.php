<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Company;
use App\Models\FundingCandidate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FundingCandidate>
 */
final class FundingCandidateFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $slug = fake()->unique()->slug(3);
        $publishedAt = CarbonImmutable::now()->subDays(fake()->numberBetween(0, 30));

        return [
            'company_id' => Company::factory(),
            'source' => 'google_news',
            'external_id' => "https://news.example.com/{$slug}",
            'title' => 'Acme raised $20M Series B',
            'url' => "https://news.example.com/{$slug}",
            'published_at' => $publishedAt,
            'matched_keywords' => ['raised', 'Series B'],
            'status' => FundingCandidate::STATUS_NEW,
            'fetched_at' => CarbonImmutable::now(),
        ];
    }
}
