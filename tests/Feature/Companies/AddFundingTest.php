<?php

declare(strict_types=1);

namespace Tests\Feature\Companies;

use App\Enums\EventType;
use App\Models\Company;
use App\Models\Event;
use App\Models\User;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AddFundingTest extends TestCase
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
    public function records_a_funding_event_and_updates_company_scalars(): void
    {
        $company = Company::factory()->create(['is_active' => true]);

        $this->actingAs($this->user)
            ->post("/companies/{$company->id}/funding", [
                'stage' => 'Series B',
                'amount_usd' => 50_000_000,
                'raised_at' => '2026-03-15',
                'url' => 'https://example.com/article',
                'notes' => 'Led by Foo Ventures',
            ])
            ->assertRedirect("/companies/{$company->id}");

        $company->refresh();
        $this->assertSame('Series B', $company->funding_stage);
        $this->assertSame(50_000_000, (int) $company->funding_last_amount_usd);
        $this->assertNotNull($company->funding_last_raised_at);
        $this->assertSame('2026-03-15', $company->funding_last_raised_at->toDateString());

        $event = Event::query()->where('company_id', $company->id)->first();
        $this->assertNotNull($event);
        $this->assertSame(EventType::FUNDING->value, $event->type);
        $body = json_decode((string) $event->body, true);
        $this->assertSame('manual', $body['source']);
        $this->assertSame(50_000_000, $body['amount_usd']);
    }

    #[Test]
    public function stale_round_does_not_overwrite_more_recent_scalars(): void
    {
        $company = Company::factory()->create([
            'is_active' => true,
            'funding_stage' => 'Series C',
            'funding_last_amount_usd' => 100_000_000,
            'funding_last_raised_at' => CarbonImmutable::parse('2026-04-01'),
        ]);

        $this->actingAs($this->user)
            ->post("/companies/{$company->id}/funding", [
                'stage' => 'Series B',
                'amount_usd' => 30_000_000,
                'raised_at' => '2025-01-01',
            ]);

        $company->refresh();
        $this->assertSame('Series C', $company->funding_stage);
        $this->assertSame(100_000_000, (int) $company->funding_last_amount_usd);

        // Event still recorded — history is history.
        $this->assertSame(1, Event::query()->where('type', EventType::FUNDING->value)->count());
    }
}
