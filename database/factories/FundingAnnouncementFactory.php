<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\FundingAnnouncement;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FundingAnnouncement>
 */
final class FundingAnnouncementFactory extends Factory
{
    protected $model = FundingAnnouncement::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $slug = fake()->unique()->slug(3);

        return [
            'source' => 'techcrunch',
            'external_id' => "https://news.example.com/{$slug}",
            'title' => 'Acme raises $20M Series A to build robots',
            'url' => "https://news.example.com/{$slug}",
            'published_at' => CarbonImmutable::now()->subDays(fake()->numberBetween(0, 20)),
            'company_name' => 'Acme',
            'company_key' => 'acme',
            'stage' => 'Series A',
            'amount_usd' => 20_000_000,
            'amount_label' => '$20M',
            'status' => FundingAnnouncement::STATUS_NEW,
            'company_id' => null,
        ];
    }
}
