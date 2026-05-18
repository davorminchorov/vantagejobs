<?php

declare(strict_types=1);

namespace Tests\Feature\Signals;

use App\Enums\EventType;
use App\Models\Company;
use App\Models\CompanySnapshot;
use App\Models\Event;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class QualityRecomputeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create(['slug' => 'primary']);
        app(TenantContext::class)->setCurrent($this->tenant);
    }

    #[Test]
    public function writes_score_and_breakdown_to_todays_snapshot(): void
    {
        $company = Company::factory()->create([
            'is_active' => true,
            'funding_last_raised_at' => CarbonImmutable::now()->subMonths(6),
            'github_org' => 'acme',
            'eng_blog_rss_url' => 'https://acme.example/feed',
        ]);

        Artisan::call('signals:quality:recompute', ['--tenant' => 'primary']);

        $row = CompanySnapshot::query()->where('company_id', $company->id)->first();
        $this->assertNotNull($row);
        $this->assertIsInt($row->quality_score);
        $this->assertGreaterThanOrEqual(1, $row->quality_score);
        $this->assertLessThanOrEqual(100, $row->quality_score);
        $this->assertIsArray($row->breakdown);
        $this->assertArrayHasKey('funding_recency', $row->breakdown);
        $this->assertArrayHasKey('layoff_penalty', $row->breakdown);
    }

    #[Test]
    public function fresh_funding_pushes_score_higher_than_no_funding(): void
    {
        $funded = Company::factory()->create([
            'is_active' => true,
            'funding_last_raised_at' => CarbonImmutable::now()->subMonths(3),
            'github_org' => 'a',
            'eng_blog_rss_url' => 'https://a.example/feed',
        ]);
        $stale = Company::factory()->create([
            'is_active' => true,
            'funding_last_raised_at' => null,
            'github_org' => 'b',
            'eng_blog_rss_url' => 'https://b.example/feed',
        ]);

        Artisan::call('signals:quality:recompute', ['--tenant' => 'primary']);

        $fundedScore = CompanySnapshot::query()->where('company_id', $funded->id)->value('quality_score');
        $staleScore = CompanySnapshot::query()->where('company_id', $stale->id)->value('quality_score');

        $this->assertGreaterThan($staleScore, $fundedScore);
    }

    #[Test]
    public function recent_layoff_event_applies_full_penalty(): void
    {
        $company = Company::factory()->create([
            'is_active' => true,
            'github_org' => 'a',
            'eng_blog_rss_url' => 'https://a.example/feed',
        ]);
        Event::query()->create([
            'company_id' => $company->id,
            'role_id' => null,
            'type' => EventType::LAYOFF->value,
            'title' => 'Layoff',
            'body' => null,
            'occurred_at' => CarbonImmutable::now()->subDays(10),
        ]);

        Artisan::call('signals:quality:recompute', ['--tenant' => 'primary']);

        $row = CompanySnapshot::query()->where('company_id', $company->id)->first();
        $this->assertSame(-20, $row->breakdown['layoff_penalty']['contribution']);
        $this->assertTrue($row->breakdown['layoff_penalty']['value']['within_90d']);
    }

    #[Test]
    public function no_eng_presence_penalty_applies_when_both_unset(): void
    {
        $company = Company::factory()->create([
            'is_active' => true,
            'github_org' => null,
            'eng_blog_rss_url' => null,
        ]);

        Artisan::call('signals:quality:recompute', ['--tenant' => 'primary']);

        $row = CompanySnapshot::query()->where('company_id', $company->id)->first();
        $this->assertSame(-10, $row->breakdown['no_eng_presence_penalty']['contribution']);
    }

    #[Test]
    public function rerun_is_idempotent(): void
    {
        $company = Company::factory()->create(['is_active' => true]);

        Artisan::call('signals:quality:recompute', ['--tenant' => 'primary']);
        Artisan::call('signals:quality:recompute', ['--tenant' => 'primary']);

        $this->assertSame(1, CompanySnapshot::query()->where('company_id', $company->id)->count());
    }
}
