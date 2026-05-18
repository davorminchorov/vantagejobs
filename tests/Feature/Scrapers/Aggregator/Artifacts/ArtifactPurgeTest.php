<?php

declare(strict_types=1);

namespace Tests\Feature\Scrapers\Aggregator\Artifacts;

use App\Models\ScrapeRunArtifact;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ArtifactPurgeTest extends TestCase
{
    use RefreshDatabase;

    private FilesystemFactory $filesystem;

    protected function setUp(): void
    {
        parent::setUp();
        $this->filesystem = $this->app->make(FilesystemFactory::class);
    }

    #[Test]
    public function purge_deletes_expired_rows_and_files_and_reports_freed_bytes(): void
    {
        $now = CarbonImmutable::now();

        $expiredPath = 'scrape-artifacts/'.$now->subDays(10)->toDateString().'/aggregator/remoteok/orphan_1_aaaa.json.gz';
        $disk = $this->filesystem->disk('local');
        $disk->put($expiredPath, gzencode('{"old":true}'));

        $expired = ScrapeRunArtifact::factory()->create([
            'path' => $expiredPath,
            'size_bytes' => 512,
            'fetched_at' => $now->subDays(10),
            'retained_until' => $now->subDays(3),
        ]);

        $freshPath = 'scrape-artifacts/'.$now->toDateString().'/aggregator/remoteok/orphan_2_bbbb.json.gz';
        $disk->put($freshPath, gzencode('{"new":true}'));

        $fresh = ScrapeRunArtifact::factory()->create([
            'path' => $freshPath,
            'size_bytes' => 256,
            'fetched_at' => $now,
            'retained_until' => $now->addDays(7),
        ]);

        Artisan::call('scrape-artifacts:purge');
        $output = Artisan::output();
        $decoded = json_decode(trim($output), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('scrape-artifacts:purge', $decoded['command']);
        $this->assertSame(1, $decoded['deleted']);
        $this->assertSame(512, $decoded['freed_bytes']);
        $this->assertSame([], $decoded['errors']);

        $this->assertFalse($disk->exists($expiredPath));
        $this->assertNull(ScrapeRunArtifact::query()->find($expired->id));

        $this->assertTrue($disk->exists($freshPath));
        $this->assertNotNull(ScrapeRunArtifact::query()->find($fresh->id));
    }

    #[Test]
    public function missing_file_is_reported_as_error_but_row_still_drops(): void
    {
        $now = CarbonImmutable::now();

        $row = ScrapeRunArtifact::factory()->create([
            'path' => 'scrape-artifacts/missing/file.json.gz',
            'size_bytes' => 1024,
            'retained_until' => $now->subDay(),
        ]);

        Artisan::call('scrape-artifacts:purge');
        $decoded = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $decoded['deleted']);
        $this->assertNotEmpty($decoded['errors']);
        $this->assertSame('file missing at sweep time', $decoded['errors'][0]['message']);
        $this->assertNull(ScrapeRunArtifact::query()->find($row->id));
    }

    #[Test]
    public function purge_is_scheduled_daily_at_three_am(): void
    {
        /** @var \Illuminate\Console\Scheduling\Schedule $schedule */
        $schedule = $this->app->make(Schedule::class);

        $matching = collect($schedule->events())->filter(
            static fn ($event): bool => str_contains((string) $event->command, 'scrape-artifacts:purge'),
        );

        $this->assertCount(1, $matching, 'scrape-artifacts:purge should be scheduled exactly once.');
        $this->assertSame('0 3 * * *', $matching->first()->expression);
    }
}
