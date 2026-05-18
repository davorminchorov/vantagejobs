<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Runs;

use App\Models\Company;
use App\Models\ScrapeRun;
use App\Models\ScrapeRunArtifact;
use App\Models\User;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class DownloadArtifactTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->setCurrent($this->tenant);
        $this->user = User::factory()->create();

        Storage::fake('local');
    }

    #[Test]
    public function unauthenticated_users_are_redirected_to_login(): void
    {
        $this->get('/admin/runs/artifacts/1/download')->assertRedirect('/login');
    }

    #[Test]
    public function streams_gzipped_body_when_client_accepts_gzip(): void
    {
        $body = json_encode(['hits' => [['id' => 1]]]);
        $gz = gzencode($body, 6);
        $this->assertNotFalse($gz);

        $path = 'scrape-artifacts/2026-05-18/aggregator/remoteok/test_1_abc.json.gz';
        Storage::disk('local')->put($path, $gz);

        $run = ScrapeRun::factory()->create([
            'company_id' => Company::factory()->create()->id,
        ]);

        $artifact = ScrapeRunArtifact::factory()->create([
            'tenant_id' => $this->tenant->id,
            'scrape_run_id' => $run->id,
            'path' => $path,
            'size_bytes' => strlen($gz),
            'retained_until' => CarbonImmutable::now()->addDays(5),
        ]);

        $response = $this->actingAs($this->user)
            ->withHeaders(['Accept-Encoding' => 'gzip, deflate'])
            ->get("/admin/runs/artifacts/{$artifact->id}/download");

        $response->assertOk();
        $response->assertHeader('Content-Encoding', 'gzip');
        $this->assertSame($gz, $response->streamedContent());
    }

    #[Test]
    public function inflates_body_when_client_does_not_accept_gzip(): void
    {
        $body = json_encode(['hits' => [['id' => 7]]]);
        $gz = gzencode($body, 6);
        $this->assertNotFalse($gz);

        $path = 'scrape-artifacts/2026-05-18/aggregator/remoteok/test_2_def.json.gz';
        Storage::disk('local')->put($path, $gz);

        $run = ScrapeRun::factory()->create([
            'company_id' => Company::factory()->create()->id,
        ]);

        $artifact = ScrapeRunArtifact::factory()->create([
            'tenant_id' => $this->tenant->id,
            'scrape_run_id' => $run->id,
            'path' => $path,
            'size_bytes' => strlen($gz),
            'retained_until' => CarbonImmutable::now()->addDays(5),
        ]);

        $response = $this->actingAs($this->user)
            ->withHeaders(['Accept-Encoding' => 'identity'])
            ->get("/admin/runs/artifacts/{$artifact->id}/download");

        $response->assertOk();
        $this->assertSame($body, $response->streamedContent());
    }

    #[Test]
    public function returns_404_when_artifact_expired(): void
    {
        $run = ScrapeRun::factory()->create([
            'company_id' => Company::factory()->create()->id,
        ]);

        $artifact = ScrapeRunArtifact::factory()->create([
            'tenant_id' => $this->tenant->id,
            'scrape_run_id' => $run->id,
            'retained_until' => CarbonImmutable::now()->subDay(),
        ]);

        $response = $this->actingAs($this->user)
            ->get("/admin/runs/artifacts/{$artifact->id}/download");

        $response->assertNotFound();
    }

    #[Test]
    public function returns_404_when_path_missing_on_disk(): void
    {
        $run = ScrapeRun::factory()->create([
            'company_id' => Company::factory()->create()->id,
        ]);

        $artifact = ScrapeRunArtifact::factory()->create([
            'tenant_id' => $this->tenant->id,
            'scrape_run_id' => $run->id,
            'path' => 'scrape-artifacts/missing/nothing_here.json.gz',
            'retained_until' => CarbonImmutable::now()->addDays(5),
        ]);

        $response = $this->actingAs($this->user)
            ->get("/admin/runs/artifacts/{$artifact->id}/download");

        $response->assertNotFound();
    }

    #[Test]
    public function returns_404_when_artifact_row_missing(): void
    {
        $response = $this->actingAs($this->user)
            ->get('/admin/runs/artifacts/9999999/download');

        $response->assertNotFound();
    }
}
