<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Runs;

use App\Enums\AggregatorSourceType;
use App\Jobs\RunAggregatorScrapeJob;
use App\Jobs\RunCompanyScrapeJob;
use App\Models\AggregatorSource;
use App\Models\Company;
use App\Models\User;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class TriggerTest extends TestCase
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
    }

    #[Test]
    public function guests_are_redirected_to_login(): void
    {
        $this->post('/admin/runs/trigger', ['scope' => 'all'])->assertRedirect('/login');
    }

    #[Test]
    public function invalid_scope_fails_validation(): void
    {
        $this->actingAs($this->user)
            ->from('/admin/runs')
            ->post('/admin/runs/trigger', ['scope' => 'bogus'])
            ->assertRedirect('/admin/runs')
            ->assertSessionHasErrors('scope');
    }

    #[Test]
    public function scope_ats_dispatches_one_job_per_ats_company(): void
    {
        Queue::fake();
        Company::factory()->create(['ats_type' => 'greenhouse', 'ats_slug' => 'gh-a']);
        Company::factory()->create(['ats_type' => 'lever', 'ats_slug' => 'lv-a']);
        Company::factory()->create(['ats_type' => null, 'ats_slug' => null]);

        $this->actingAs($this->user)->post('/admin/runs/trigger', ['scope' => 'ats']);

        Queue::assertPushed(RunCompanyScrapeJob::class, 2);
        Queue::assertNotPushed(RunAggregatorScrapeJob::class);
    }

    #[Test]
    public function scope_ats_type_dispatches_only_that_type(): void
    {
        Queue::fake();
        Company::factory()->create(['ats_type' => 'greenhouse', 'ats_slug' => 'gh-a']);
        Company::factory()->create(['ats_type' => 'greenhouse', 'ats_slug' => 'gh-b']);
        Company::factory()->create(['ats_type' => 'lever', 'ats_slug' => 'lv-a']);

        $this->actingAs($this->user)->post('/admin/runs/trigger', ['scope' => 'ats:greenhouse']);

        Queue::assertPushed(RunCompanyScrapeJob::class, 2);
    }

    #[Test]
    public function scope_aggregators_dispatches_only_enabled_sources(): void
    {
        Queue::fake();
        AggregatorSource::factory()->ofType(AggregatorSourceType::REMOTIVE)->create(['enabled' => true]);
        AggregatorSource::factory()->ofType(AggregatorSourceType::HIMALAYAS)->create(['enabled' => true]);
        AggregatorSource::factory()->ofType(AggregatorSourceType::JOBICY)->create(['enabled' => false]);

        $this->actingAs($this->user)->post('/admin/runs/trigger', ['scope' => 'aggregators']);

        Queue::assertPushed(RunAggregatorScrapeJob::class, 2);
        Queue::assertNotPushed(RunCompanyScrapeJob::class);
    }

    #[Test]
    public function scope_single_aggregator_dispatches_one_job_even_if_disabled(): void
    {
        Queue::fake();
        AggregatorSource::factory()->ofType(AggregatorSourceType::JOBICY)->create(['enabled' => false]);

        $this->actingAs($this->user)->post('/admin/runs/trigger', ['scope' => 'aggregator:jobicy']);

        Queue::assertPushed(RunAggregatorScrapeJob::class, 1);
    }

    #[Test]
    public function scope_all_dispatches_ats_companies_and_enabled_aggregators(): void
    {
        Queue::fake();
        Company::factory()->create(['ats_type' => 'greenhouse', 'ats_slug' => 'gh-a']);
        AggregatorSource::factory()->ofType(AggregatorSourceType::REMOTIVE)->create(['enabled' => true]);

        $this->actingAs($this->user)->post('/admin/runs/trigger', ['scope' => 'all']);

        Queue::assertPushed(RunCompanyScrapeJob::class, 1);
        Queue::assertPushed(RunAggregatorScrapeJob::class, 1);
    }

    #[Test]
    public function empty_scope_target_dispatches_nothing_and_flashes(): void
    {
        Queue::fake();

        $this->actingAs($this->user)
            ->post('/admin/runs/trigger', ['scope' => 'ats'])
            ->assertSessionHas('success', 'Nothing to run for that selection.');

        Queue::assertNothingPushed();
    }

    #[Test]
    public function it_does_not_dispatch_other_tenants_companies(): void
    {
        Queue::fake();
        Company::factory()->create(['ats_type' => 'greenhouse', 'ats_slug' => 'mine']);

        $other = Tenant::factory()->create();
        app(TenantContext::class)->withTenant($other, function (): void {
            Company::factory()->create(['ats_type' => 'greenhouse', 'ats_slug' => 'theirs']);
        });

        $this->actingAs($this->user)->post('/admin/runs/trigger', ['scope' => 'ats']);

        Queue::assertPushed(RunCompanyScrapeJob::class, 1);
    }
}
