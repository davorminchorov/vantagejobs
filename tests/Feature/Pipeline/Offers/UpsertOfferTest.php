<?php

declare(strict_types=1);

namespace Tests\Feature\Pipeline\Offers;

use App\Enums\EventType;
use App\Enums\PipelineStatus;
use App\Http\Controllers\Pipeline\Offers\UpsertController;
use App\Models\Event;
use App\Models\Offer;
use App\Models\OfferRevision;
use App\Models\Role;
use App\Models\User;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class UpsertOfferTest extends TestCase
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

        Route::middleware(['web', 'auth'])->group(function (): void {
            Route::post('/pipeline/roles/{role}/offer', UpsertController::class)
                ->name('pipeline.roles.offer.upsert');
        });
    }

    #[Test]
    public function it_creates_an_offer_with_a_computed_total_comp_snapshot(): void
    {
        $role = Role::factory()->create(['pipeline_status' => PipelineStatus::OFFER->value]);

        $this->actingAs($this->user)
            ->post("/pipeline/roles/{$role->id}/offer", [
                'currency' => 'USD',
                'base' => 180_000,
                'bonus' => 20_000,
                'signing_bonus' => 0,
                'vesting_years' => 4,
                'decision_deadline' => '2026-07-01',
            ])
            ->assertRedirect();

        $offer = Offer::query()->where('role_id', $role->id)->firstOrFail();
        $this->assertSame('USD', $offer->currency);
        $this->assertSame(200_000, (int) $offer->computed_total_comp_usd);
        $this->assertSame($this->tenant->id, $offer->tenant_id);
        $this->assertSame('2026-07-01', $offer->decision_deadline->toDateString());
    }

    #[Test]
    public function it_appends_a_revision_on_every_save(): void
    {
        $role = Role::factory()->create(['pipeline_status' => PipelineStatus::OFFER->value]);

        $this->actingAs($this->user)->post("/pipeline/roles/{$role->id}/offer", [
            'currency' => 'USD',
            'base' => 150_000,
        ]);

        $this->actingAs($this->user)->post("/pipeline/roles/{$role->id}/offer", [
            'currency' => 'USD',
            'base' => 170_000,
        ]);

        // One current offer (updateOrCreate), two revisions.
        $this->assertSame(1, Offer::query()->where('role_id', $role->id)->count());

        $offer = Offer::query()->where('role_id', $role->id)->firstOrFail();
        $this->assertSame(170_000, (int) $offer->base);

        $revisions = OfferRevision::query()->where('offer_id', $offer->id)->orderBy('id')->get();
        $this->assertCount(2, $revisions);
        $this->assertSame(150_000, $revisions[0]->snapshot['base']);
        $this->assertSame(170_000, $revisions[1]->snapshot['base']);
        $this->assertNull($revisions[0]->getAttribute('updated_at'));
    }

    #[Test]
    public function it_writes_an_offer_recorded_event(): void
    {
        $role = Role::factory()->create(['pipeline_status' => PipelineStatus::OFFER->value]);

        $this->actingAs($this->user)->post("/pipeline/roles/{$role->id}/offer", [
            'currency' => 'USD',
            'base' => 200_000,
        ]);

        $event = Event::query()
            ->where('role_id', $role->id)
            ->where('type', EventType::OFFER_RECORDED->value)
            ->firstOrFail();

        $this->assertSame($role->company_id, $event->company_id);
        $body = json_decode((string) $event->body, true);
        $this->assertSame(200_000, $body['computed_total_comp_usd']);
        $this->assertSame('manual', $body['source']);
    }

    #[Test]
    public function currency_is_required(): void
    {
        $role = Role::factory()->create(['pipeline_status' => PipelineStatus::OFFER->value]);

        $this->actingAs($this->user)
            ->post("/pipeline/roles/{$role->id}/offer", [
                'base' => 100_000,
            ])
            ->assertSessionHasErrors('currency');

        $this->assertSame(0, Offer::query()->count());
    }

    #[Test]
    public function guests_cannot_record_an_offer(): void
    {
        $role = Role::factory()->create(['pipeline_status' => PipelineStatus::OFFER->value]);

        $this->post("/pipeline/roles/{$role->id}/offer", [
            'currency' => 'USD',
            'base' => 100_000,
        ])->assertRedirect('/login');

        $this->assertSame(0, Offer::query()->count());
    }

    #[Test]
    public function offers_are_scoped_to_the_current_tenant(): void
    {
        $otherTenant = Tenant::factory()->create();
        app(TenantContext::class)->setCurrent($otherTenant);
        $otherRole = Role::factory()->create(['pipeline_status' => PipelineStatus::OFFER->value]);
        app(TenantContext::class)->setCurrent($this->tenant);

        $this->actingAs($this->user)
            ->post("/pipeline/roles/{$otherRole->id}/offer", [
                'currency' => 'USD',
                'base' => 100_000,
            ])
            ->assertNotFound();

        $this->assertSame(0, Offer::query()->count());
    }
}
