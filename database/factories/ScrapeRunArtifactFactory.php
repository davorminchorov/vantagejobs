<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ScrapeRunArtifact;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScrapeRunArtifact>
 */
final class ScrapeRunArtifactFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $now = CarbonImmutable::now();

        return [
            'tenant_id' => null,
            'scrape_run_id' => null,
            'source_kind' => 'aggregator',
            'source_id' => null,
            'company_id' => null,
            'connector' => 'remoteok',
            'request_endpoint' => 'https://remoteok.com/api',
            'http_status' => 200,
            'path' => sprintf('%s/remoteok/orphan_1_%s.json.gz', $now->toDateString(), bin2hex(random_bytes(4))),
            'size_bytes' => 1024,
            'sha256' => hash('sha256', 'fixture'),
            'retained_until' => $now->addDays(7),
            'fetched_at' => $now,
        ];
    }
}
