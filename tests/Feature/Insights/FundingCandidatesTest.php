<?php

declare(strict_types=1);

namespace Tests\Feature\Insights;

use App\Enums\EventType;
use App\Models\Company;
use App\Models\Event;
use App\Models\FundingCandidate;
use App\Models\User;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class FundingCandidatesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->setCurrent($this->tenant);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);

    }

    #[Test]
    public function index_groups_new_candidates_by_company_newest_first(): void
    {
        $older = Company::factory()->create(['name' => 'Older Co', 'funding_stage' => 'Series A']);
        $newer = Company::factory()->create(['name' => 'Newer Co']);
        FundingCandidate::factory()->create([
            'company_id' => $older->id,
            'title' => 'Older Co raised $5M Series A - TechCrunch',
            'published_at' => CarbonImmutable::parse('2026-09-01'),
        ]);
        $new = FundingCandidate::factory()->create([
            'company_id' => $newer->id,
            'title' => 'Newer Co: $150 Million Series D At $4 Billion Valuation - Pulse 2.0',
            'published_at' => CarbonImmutable::parse('2026-10-20'),
        ]);
        FundingCandidate::factory()->create([
            'company_id' => $newer->id,
            'published_at' => CarbonImmutable::parse('2026-10-01'),
        ]);
        FundingCandidate::factory()->create(['company_id' => $older->id, 'status' => FundingCandidate::STATUS_CONFIRMED]);
        FundingCandidate::factory()->create(['company_id' => $older->id, 'status' => FundingCandidate::STATUS_DISMISSED]);

        $response = $this->actingAs($this->user)->get('/insights/funding-candidates');
        $response->assertOk();

        $props = $response->viewData('page')['props'];
        $this->assertSame(['headlines' => 3, 'companies' => 2, 'max_age_months' => 12], $props['summary']);
        $this->assertSame(['Newer Co', 'Older Co'], array_column(array_column($props['groups'], 'company'), 'name'));
        $this->assertSame('Series A', $props['groups'][1]['company']['funding']['stage']);

        $top = $props['groups'][0]['candidates'][0];
        $this->assertSame($new->id, $top['id']);
        $this->assertSame('Newer Co: $150 Million Series D At $4 Billion Valuation', $top['headline']);
        $this->assertSame('Pulse 2.0', $top['publisher']);
        $this->assertSame('Series D', $top['stage']);
        $this->assertSame(150_000_000, $top['amount_usd']);
        $this->assertSame('$150M', $top['amount_label']);
        $this->assertCount(2, $props['groups'][0]['candidates']);
    }

    #[Test]
    public function confirm_flips_status_and_writes_funding_event(): void
    {
        $company = Company::factory()->create();
        $candidate = FundingCandidate::factory()->create([
            'company_id' => $company->id,
            'status' => FundingCandidate::STATUS_NEW,
            'title' => 'Acme raised $20M Series B',
            'url' => 'https://news.example.com/x',
            'matched_keywords' => ['raised', 'Series B'],
        ]);

        $this->actingAs($this->user)
            ->post("/insights/funding-candidates/{$candidate->id}/confirm")
            ->assertRedirect();

        $this->assertSame(
            FundingCandidate::STATUS_CONFIRMED,
            FundingCandidate::query()->find($candidate->id)?->status,
        );

        $event = Event::query()
            ->where('company_id', $company->id)
            ->where('type', EventType::FUNDING->value)
            ->first();
        $this->assertNotNull($event);
        $this->assertSame('Acme raised $20M Series B', $event->title);

        $body = json_decode((string) $event->body, true);
        $this->assertSame('google_news', $body['source']);
        $this->assertSame($candidate->id, $body['candidate_id']);
    }

    #[Test]
    public function confirm_with_reviewed_details_updates_company_funding(): void
    {
        $company = Company::factory()->create(['funding_stage' => null]);
        $candidate = FundingCandidate::factory()->create([
            'company_id' => $company->id,
            'title' => 'Acme raised $20M Series B - TechCrunch',
            'url' => 'https://news.example.com/x',
        ]);

        $this->actingAs($this->user)
            ->post("/insights/funding-candidates/{$candidate->id}/confirm", [
                'stage' => 'Series B',
                'amount_usd' => 20_000_000,
                'raised_at' => '2026-10-02',
                'notes' => 'Led by Index',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $company->refresh();
        $this->assertSame('Series B', $company->funding_stage);
        $this->assertSame(20_000_000, $company->funding_last_amount_usd);
        $this->assertSame('2026-10-02', $company->funding_last_raised_at?->toDateString());

        $event = Event::query()->where('company_id', $company->id)->where('type', EventType::FUNDING->value)->sole();
        $this->assertSame('Acme raised $20M Series B', $event->title);
        $body = json_decode((string) $event->body, true);
        $this->assertSame('google_news', $body['source']);
        $this->assertSame(20_000_000, $body['amount_usd']);
        $this->assertSame('Led by Index', $body['notes']);
        $this->assertSame('https://news.example.com/x', $body['url']);
    }

    #[Test]
    public function one_click_confirm_uses_the_article_date(): void
    {
        $company = Company::factory()->create();
        $candidate = FundingCandidate::factory()->create([
            'company_id' => $company->id,
            'published_at' => CarbonImmutable::parse('2026-08-14 09:00:00'),
        ]);

        $this->actingAs($this->user)->post("/insights/funding-candidates/{$candidate->id}/confirm")->assertRedirect();

        $this->assertSame('2026-08-14', $company->refresh()->funding_last_raised_at?->toDateString());
    }

    #[Test]
    public function confirm_rejects_invalid_details_and_keeps_the_candidate_new(): void
    {
        $candidate = FundingCandidate::factory()->create();

        $this->actingAs($this->user)
            ->post("/insights/funding-candidates/{$candidate->id}/confirm", ['amount_usd' => -5, 'raised_at' => 'not a date'])
            ->assertSessionHasErrors(['amount_usd', 'raised_at']);

        $this->assertSame(FundingCandidate::STATUS_NEW, $candidate->refresh()->status);
        $this->assertSame(0, Event::query()->where('type', EventType::FUNDING->value)->count());
    }

    #[Test]
    public function dismiss_all_clears_only_new_candidates_for_that_company(): void
    {
        $company = Company::factory()->create();
        $other = Company::factory()->create();
        FundingCandidate::factory()->count(2)->create(['company_id' => $company->id]);
        $confirmed = FundingCandidate::factory()->create(['company_id' => $company->id, 'status' => FundingCandidate::STATUS_CONFIRMED]);
        $untouched = FundingCandidate::factory()->create(['company_id' => $other->id]);

        $this->actingAs($this->user)
            ->post("/insights/funding-candidates/companies/{$company->id}/dismiss")
            ->assertRedirect();

        $this->assertSame(2, FundingCandidate::query()->where('company_id', $company->id)->where('status', FundingCandidate::STATUS_DISMISSED)->count());
        $this->assertSame(FundingCandidate::STATUS_CONFIRMED, $confirmed->refresh()->status);
        $this->assertSame(FundingCandidate::STATUS_NEW, $untouched->refresh()->status);
    }

    #[Test]
    public function dismiss_flips_status_without_creating_event(): void
    {
        $company = Company::factory()->create();
        $candidate = FundingCandidate::factory()->create([
            'company_id' => $company->id,
            'status' => FundingCandidate::STATUS_NEW,
        ]);

        $this->actingAs($this->user)
            ->post("/insights/funding-candidates/{$candidate->id}/dismiss")
            ->assertRedirect();

        $this->assertSame(
            FundingCandidate::STATUS_DISMISSED,
            FundingCandidate::query()->find($candidate->id)?->status,
        );

        $this->assertSame(
            0,
            Event::query()->where('type', EventType::FUNDING->value)->count(),
        );
    }
}
