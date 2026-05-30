<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Enums\ExportJobStatus;
use App\Jobs\BuildDataExportJob;
use App\Models\Company;
use App\Models\ExportJob;
use App\Models\User;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ZipArchive;

final class DataExportTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->tenant = Tenant::factory()->create(['slug' => 'data-export-test']);
        app(TenantContext::class)->setCurrent($this->tenant);

        $this->user = User::factory()->create();
    }

    #[Test]
    public function the_data_settings_page_renders_for_authenticated_users(): void
    {
        ExportJob::factory()->completed()->create(['user_id' => $this->user->id]);

        $this->actingAs($this->user)
            ->get('/settings/data')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Settings/Data')
                ->has('exports', 1)
            );
    }

    #[Test]
    public function guests_cannot_view_the_data_settings_page(): void
    {
        $this->get('/settings/data')->assertRedirect('/login');
    }

    #[Test]
    public function guests_cannot_request_an_export(): void
    {
        $this->post('/settings/data/export')->assertRedirect('/login');
    }

    #[Test]
    public function requesting_an_export_creates_a_pending_job_and_dispatches_the_build(): void
    {
        Bus::fake();

        $this->actingAs($this->user)
            ->post('/settings/data/export')
            ->assertRedirect('/settings/data')
            ->assertSessionHas('success');

        $job = ExportJob::query()->firstOrFail();
        $this->assertSame((int) $this->user->id, (int) $job->user_id);
        $this->assertSame($this->tenant->id, (int) $job->tenant_id);
        $this->assertSame(['sql', 'json', 'csv'], $job->formats);

        Bus::assertDispatched(BuildDataExportJob::class);
    }

    #[Test]
    public function the_build_job_produces_a_downloadable_zip_with_a_manifest(): void
    {
        Company::factory()->create(['name' => 'Acme Inc']);

        $job = ExportJob::factory()->create(['user_id' => $this->user->id]);

        (new BuildDataExportJob((int) $job->id, $this->tenant->id))
            ->handle(app(TenantContext::class), app(\App\Exports\DataExportBuilder::class));

        $job->refresh();

        $this->assertSame(ExportJobStatus::COMPLETED, $job->status);
        $this->assertNotNull($job->path);
        $this->assertNotNull($job->expires_at);
        $this->assertNotNull($job->schema_version);

        Storage::disk('local')->assertExists($job->path);

        $manifest = $job->manifest;
        $this->assertIsArray($manifest);
        $this->assertArrayHasKey('schema_version', $manifest);
        $this->assertArrayHasKey('counts', $manifest);
        $this->assertSame(1, $manifest['counts']['companies']);

        $this->assertZipContains($job->path, [
            'manifest.json',
            'json/companies.json',
            'csv/companies.csv',
            'sql/data.sql',
        ]);
    }

    #[Test]
    public function the_export_only_contains_the_requesting_tenants_rows(): void
    {
        $context = app(TenantContext::class);

        Company::factory()->create(['name' => 'Mine']);

        $otherTenant = Tenant::factory()->create(['slug' => 'other-tenant']);
        $context->withTenant($otherTenant, function (): void {
            Company::factory()->create(['name' => 'Theirs']);
        });

        $context->setCurrent($this->tenant);

        $job = ExportJob::factory()->create(['user_id' => $this->user->id]);

        (new BuildDataExportJob((int) $job->id, $this->tenant->id))
            ->handle($context, app(\App\Exports\DataExportBuilder::class));

        $job->refresh();
        $this->assertSame(1, $job->manifest['counts']['companies']);

        $companies = $this->zipEntryContents($job->path, 'json/companies.json');
        $this->assertStringContainsString('Mine', $companies);
        $this->assertStringNotContainsString('Theirs', $companies);
    }

    #[Test]
    public function a_user_can_download_a_completed_export_within_the_window(): void
    {
        $disk = app(FilesystemFactory::class)->disk('local');
        $disk->put('exports/bundle.zip', 'zip-bytes');

        $job = ExportJob::factory()->completed()->create([
            'user_id' => $this->user->id,
            'path' => 'exports/bundle.zip',
            'expires_at' => CarbonImmutable::now()->addHours(2),
        ]);

        $this->actingAs($this->user)
            ->get("/settings/data/{$job->id}/download")
            ->assertOk()
            ->assertHeader('content-type', 'application/zip');
    }

    #[Test]
    public function an_expired_export_cannot_be_downloaded(): void
    {
        $disk = app(FilesystemFactory::class)->disk('local');
        $disk->put('exports/old.zip', 'zip-bytes');

        $job = ExportJob::factory()->completed()->create([
            'user_id' => $this->user->id,
            'path' => 'exports/old.zip',
            'expires_at' => CarbonImmutable::now()->subHour(),
        ]);

        $this->actingAs($this->user)
            ->get("/settings/data/{$job->id}/download")
            ->assertNotFound();
    }

    #[Test]
    public function a_pending_export_cannot_be_downloaded(): void
    {
        $job = ExportJob::factory()->create(['user_id' => $this->user->id]);

        $this->actingAs($this->user)
            ->get("/settings/data/{$job->id}/download")
            ->assertNotFound();
    }

    #[Test]
    public function one_tenant_cannot_download_another_tenants_export(): void
    {
        $disk = app(FilesystemFactory::class)->disk('local');
        $disk->put('exports/secret.zip', 'zip-bytes');

        $otherTenant = Tenant::factory()->create(['slug' => 'other-download-tenant']);
        $otherJob = app(TenantContext::class)->withTenant(
            $otherTenant,
            fn (): ExportJob => ExportJob::factory()->completed()->create([
                'user_id' => $this->user->id,
                'path' => 'exports/secret.zip',
                'expires_at' => CarbonImmutable::now()->addHours(2),
            ]),
        );

        app(TenantContext::class)->setCurrent($this->tenant);

        $this->actingAs($this->user)
            ->get("/settings/data/{$otherJob->id}/download")
            ->assertNotFound();
    }

    #[Test]
    public function guests_cannot_download_an_export(): void
    {
        $job = ExportJob::factory()->completed()->create([
            'user_id' => $this->user->id,
            'expires_at' => CarbonImmutable::now()->addHours(2),
        ]);

        $this->get("/settings/data/{$job->id}/download")->assertRedirect('/login');
    }

    #[Test]
    public function the_prune_command_expires_jobs_past_their_window_and_deletes_the_file(): void
    {
        $disk = app(FilesystemFactory::class)->disk('local');
        $disk->put('exports/stale.zip', 'zip-bytes');

        $job = ExportJob::factory()->completed()->create([
            'user_id' => $this->user->id,
            'path' => 'exports/stale.zip',
            'expires_at' => CarbonImmutable::now()->subHour(),
        ]);

        $this->artisan('exports:prune')->assertSuccessful();

        $job->refresh();
        $this->assertSame(ExportJobStatus::EXPIRED, $job->status);
        $this->assertNull($job->path);
        Storage::disk('local')->assertMissing('exports/stale.zip');
    }

    #[Test]
    public function the_prune_command_leaves_in_window_exports_untouched(): void
    {
        $disk = app(FilesystemFactory::class)->disk('local');
        $disk->put('exports/fresh.zip', 'zip-bytes');

        $job = ExportJob::factory()->completed()->create([
            'user_id' => $this->user->id,
            'path' => 'exports/fresh.zip',
            'expires_at' => CarbonImmutable::now()->addHours(5),
        ]);

        $this->artisan('exports:prune')->assertSuccessful();

        $job->refresh();
        $this->assertSame(ExportJobStatus::COMPLETED, $job->status);
        Storage::disk('local')->assertExists('exports/fresh.zip');
    }

    /**
     * @param  list<string>  $expectedEntries
     */
    private function assertZipContains(string $path, array $expectedEntries): void
    {
        $absolute = app(FilesystemFactory::class)->disk('local')->path($path);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($absolute) === true, "Could not open zip at {$path}");

        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $zip->close();

        foreach ($expectedEntries as $entry) {
            $this->assertContains($entry, $names, "Expected {$entry} in export archive.");
        }
    }

    private function zipEntryContents(string $path, string $entry): string
    {
        $absolute = app(FilesystemFactory::class)->disk('local')->path($path);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($absolute) === true);
        $contents = $zip->getFromName($entry);
        $zip->close();

        $this->assertNotFalse($contents, "Entry {$entry} missing from archive.");

        return $contents;
    }
}
