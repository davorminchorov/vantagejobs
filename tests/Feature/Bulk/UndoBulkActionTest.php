<?php

declare(strict_types=1);

namespace Tests\Feature\Bulk;

use App\Bulk\BulkActionExecutor;
use App\Enums\AggregatorListingStatus;
use App\Enums\BulkAction;
use App\Models\AggregatorListing;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Role;
use App\Models\Tag;
use App\Models\User;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class UndoBulkActionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    private BulkActionExecutor $executor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->setCurrent($this->tenant);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->executor = app(BulkActionExecutor::class);
    }

    #[Test]
    public function undo_restores_prior_tier_within_the_window(): void
    {
        $company = Company::factory()->create(['tier' => 'C']);
        $log = $this->executor->perform(BulkAction::CHANGE_TIER, 'company', [$company->id], ['tier' => 'A'], $this->user);

        $this->assertSame('A', $company->refresh()->tier);

        $this->actingAs($this->user)
            ->post("/bulk-actions/{$log->id}/undo")
            ->assertRedirect();

        $this->assertSame('C', $company->refresh()->tier);
    }

    #[Test]
    public function undo_restores_dismissed_listing_status(): void
    {
        $listing = AggregatorListing::factory()->create(['status' => AggregatorListingStatus::NEW]);
        $log = $this->executor->perform(BulkAction::DISMISS, 'aggregator_listing', [$listing->id], [], $this->user);

        $this->assertSame(AggregatorListingStatus::DISMISSED, $listing->refresh()->status);

        $this->actingAs($this->user)
            ->post("/bulk-actions/{$log->id}/undo")
            ->assertRedirect();

        $this->assertSame(AggregatorListingStatus::NEW, $listing->refresh()->status);
    }

    #[Test]
    public function undo_reopens_a_role_marked_closed(): void
    {
        $company = Company::factory()->create();
        $role = Role::factory()->create(['company_id' => $company->id, 'is_open' => true]);
        $log = $this->executor->perform(BulkAction::MARK_CLOSED, 'role', [$role->id], [], $this->user);

        $this->assertFalse($role->refresh()->is_open);

        $this->actingAs($this->user)
            ->post("/bulk-actions/{$log->id}/undo")
            ->assertRedirect();

        $this->assertTrue($role->refresh()->is_open);
    }

    #[Test]
    public function undo_removes_a_tag_that_the_action_added(): void
    {
        $company = Company::factory()->create();
        $log = $this->executor->perform(BulkAction::TAG, 'company', [$company->id], ['tag' => 'urgent'], $this->user);

        $tag = Tag::query()->where('slug', 'urgent')->sole();
        $this->assertTrue($company->fresh()->tags->contains($tag->id));

        $this->actingAs($this->user)
            ->post("/bulk-actions/{$log->id}/undo")
            ->assertRedirect();

        $this->assertFalse($company->fresh()->tags->contains($tag->id));
    }

    #[Test]
    public function undo_keeps_a_tag_that_was_already_present(): void
    {
        $company = Company::factory()->create();
        $tag = Tag::query()->create(['slug' => 'keep', 'name' => 'keep']);
        $company->tags()->attach($tag->id);

        $log = $this->executor->perform(BulkAction::TAG, 'company', [$company->id], ['tag' => 'keep'], $this->user);

        $this->actingAs($this->user)
            ->post("/bulk-actions/{$log->id}/undo")
            ->assertRedirect();

        // The tag was attached before the bulk action, so undo leaves it.
        $this->assertTrue($company->fresh()->tags->contains($tag->id));
    }

    #[Test]
    public function undo_writes_a_reversal_audit_row(): void
    {
        $company = Company::factory()->create(['tier' => 'C']);
        $log = $this->executor->perform(BulkAction::CHANGE_TIER, 'company', [$company->id], ['tier' => 'A'], $this->user);

        $this->actingAs($this->user)
            ->post("/bulk-actions/{$log->id}/undo")
            ->assertRedirect();

        $reversal = AuditLog::query()->where('action', BulkActionExecutor::REVERSAL_ACTION)->sole();
        $this->assertSame($log->id, $reversal->context['reversed_audit_log_id']);
    }

    #[Test]
    public function undo_is_rejected_after_the_window_lapses(): void
    {
        $company = Company::factory()->create(['tier' => 'C']);
        $log = $this->executor->perform(BulkAction::CHANGE_TIER, 'company', [$company->id], ['tier' => 'A'], $this->user);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(61));

        $this->actingAs($this->user)
            ->post("/bulk-actions/{$log->id}/undo")
            ->assertRedirect();

        // Tier remains changed; the expired window short-circuits the revert.
        $this->assertSame('A', $company->refresh()->tier);
        $this->assertSame(0, AuditLog::query()->where('action', BulkActionExecutor::REVERSAL_ACTION)->count());

        CarbonImmutable::setTestNow();
    }

    #[Test]
    public function the_same_action_cannot_be_undone_twice(): void
    {
        $company = Company::factory()->create(['tier' => 'C']);
        $log = $this->executor->perform(BulkAction::CHANGE_TIER, 'company', [$company->id], ['tier' => 'A'], $this->user);

        $this->actingAs($this->user)->post("/bulk-actions/{$log->id}/undo")->assertRedirect();
        $this->assertSame('C', $company->refresh()->tier);

        // Re-apply a change so a second undo would be observable if it ran.
        Company::query()->whereKey($company->id)->update(['tier' => 'B']);

        $this->actingAs($this->user)->post("/bulk-actions/{$log->id}/undo")->assertRedirect();

        $this->assertSame('B', $company->refresh()->tier);
        $this->assertSame(1, AuditLog::query()->where('action', BulkActionExecutor::REVERSAL_ACTION)->count());
    }

    #[Test]
    public function guests_cannot_undo(): void
    {
        $company = Company::factory()->create(['tier' => 'C']);
        $log = $this->executor->perform(BulkAction::CHANGE_TIER, 'company', [$company->id], ['tier' => 'A'], $this->user);

        $this->post("/bulk-actions/{$log->id}/undo")->assertRedirect('/login');

        $this->assertSame('A', $company->refresh()->tier);
    }

    #[Test]
    public function a_log_from_another_tenant_cannot_be_undone(): void
    {
        $otherTenant = Tenant::factory()->create();
        $log = app(TenantContext::class)->withTenant($otherTenant, function () use ($otherTenant): AuditLog {
            $user = User::factory()->create(['tenant_id' => $otherTenant->id]);
            $company = Company::factory()->create(['tier' => 'C']);

            return $this->executor->perform(BulkAction::CHANGE_TIER, 'company', [$company->id], ['tier' => 'A'], $user);
        });

        $this->actingAs($this->user)
            ->post("/bulk-actions/{$log->id}/undo")
            ->assertRedirect();

        $this->assertSame(0, AuditLog::query()->where('action', BulkActionExecutor::REVERSAL_ACTION)->count());
    }
}
