<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AggregatorListing;
use App\Models\Bookmark;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Bookmark>
 */
final class BookmarkFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'bookmarkable_type' => 'aggregator_listing',
            'bookmarkable_id' => AggregatorListing::factory(),
            'notes' => null,
            'expires_at' => CarbonImmutable::now()->addDays(30),
            'expires_warned_at' => null,
        ];
    }
}
