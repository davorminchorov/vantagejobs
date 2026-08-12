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
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ShowTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->setCurrent($this->tenant);
        $this->user = User::factory()->admin()->create();
    }

    #[Test]
    public function unauthenticated_users_are_redirected_to_login(): void
    {
        $this->get('/admin/runs/1')->assertRedirect('/login');
    }

    #[Test]
    public function renders_run_summary_with_errors_and_artifacts(): void
    {
        $company = Company::factory()->create();
        $run = ScrapeRun::factory()->create([
            'company_id' => $company->id,
            'source_kind' => 'ats',
            'source' => 'greenhouse',
            'status' => 'partial',
            'errors' => [
                ['message' => 'rate limited', 'context' => ['retry_after' => 60]],
                ['message' => 'parse failed'],
            ],
        ]);

        $artifact = ScrapeRunArtifact::factory()->create([
            'tenant_id' => $this->tenant->id,
            'scrape_run_id' => $run->id,
            'connector' => 'remoteok',
            'retained_until' => CarbonImmutable::now()->addDays(5),
        ]);

        $response = $this->actingAs($this->user)->get("/admin/runs/{$run->id}");

        $response->assertOk();
        $props = $response->viewData('page')['props'];

        $this->assertSame((int) $run->id, $props['run']['id']);
        $this->assertSame('partial', $props['run']['status']);
        $this->assertSame(2, $props['run']['error_count']);

        $this->assertCount(2, $props['errors']);
        $this->assertSame('rate limited', $props['errors'][0]['message']);
        $this->assertSame(60, $props['errors'][0]['context']['retry_after']);

        $this->assertCount(1, $props['artifacts']);
        $this->assertSame((int) $artifact->id, $props['artifacts'][0]['id']);
        $this->assertFalse($props['artifacts'][0]['expired']);
        $this->assertNotNull($props['artifacts'][0]['download_url']);
    }

    #[Test]
    public function expired_artifacts_show_no_download_link(): void
    {
        $company = Company::factory()->create();
        $run = ScrapeRun::factory()->create(['company_id' => $company->id]);

        ScrapeRunArtifact::factory()->create([
            'tenant_id' => $this->tenant->id,
            'scrape_run_id' => $run->id,
            'retained_until' => CarbonImmutable::now()->subDay(),
        ]);

        $response = $this->actingAs($this->user)->get("/admin/runs/{$run->id}");

        $response->assertOk();
        $props = $response->viewData('page')['props'];
        $this->assertCount(1, $props['artifacts']);
        $this->assertTrue($props['artifacts'][0]['expired']);
        $this->assertNull($props['artifacts'][0]['download_url']);
    }

    #[Test]
    public function returns_404_when_run_not_found(): void
    {
        $response = $this->actingAs($this->user)->get('/admin/runs/9999999');
        $response->assertNotFound();
    }
}
