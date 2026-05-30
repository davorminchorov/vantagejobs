<?php

declare(strict_types=1);

namespace Tests\Feature\Bulk;

use App\Bulk\BulkActionExecutor;
use App\Enums\AggregatorListingStatus;
use App\Models\AggregatorListing;
use App\Models\AuditLog;
use App\Models\Bookmark;
use App\Models\Company;
use App\Models\Role;
use App\Models\Tag;
use App\Models\User;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class PerformBulkActionTest extends TestCase
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
    public function change_tier_updates_rows_and_records_one_audit_log(): void
    {
        $a = Company::factory()->create(['tier' => 'C']);
        $b = Company::factory()->create(['tier' => 'B']);

        $this->actingAs($this->user)
            ->post('/bulk-actions', [
                'entity' => 'company',
                'action' => 'change_tier',
                'ids' => [$a->id, $b->id],
                'tier' => 'A',
            ])
            ->assertRedirect();

        $this->assertSame('A', $a->refresh()->tier);
        $this->assertSame('A', $b->refresh()->tier);

        $log = AuditLog::query()->where('action', BulkActionExecutor::ACTION)->sole();
        $this->assertSame('change_tier', $log->context['action']);
        $this->assertSame('company', $log->context['entity']);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $log->context['ids']);
        $this->assertCount(2, $log->context['before']);
        $this->assertContains('C', array_column($log->context['before'], 'old'));
    }

    #[Test]
    public function tag_attaches_a_tag_to_each_company(): void
    {
        $a = Company::factory()->create();
        $b = Company::factory()->create();

        $this->actingAs($this->user)
            ->post('/bulk-actions', [
                'entity' => 'company',
                'action' => 'tag',
                'ids' => [$a->id, $b->id],
                'tag' => 'High Priority',
            ])
            ->assertRedirect();

        $tag = Tag::query()->where('slug', 'high-priority')->sole();
        $this->assertTrue($a->fresh()->tags->contains($tag->id));
        $this->assertTrue($b->fresh()->tags->contains($tag->id));
    }

    #[Test]
    public function dismiss_moves_companies_to_graveyard(): void
    {
        $company = Company::factory()->create(['status' => 'researching']);

        $this->actingAs($this->user)
            ->post('/bulk-actions', [
                'entity' => 'company',
                'action' => 'dismiss',
                'ids' => [$company->id],
            ])
            ->assertRedirect();

        $this->assertSame('graveyard', $company->refresh()->status);
    }

    #[Test]
    public function dismiss_sets_listing_status_to_dismissed(): void
    {
        $listing = AggregatorListing::factory()->create(['status' => AggregatorListingStatus::NEW]);

        $this->actingAs($this->user)
            ->post('/bulk-actions', [
                'entity' => 'aggregator_listing',
                'action' => 'dismiss',
                'ids' => [$listing->id],
            ])
            ->assertRedirect();

        $this->assertSame(AggregatorListingStatus::DISMISSED, $listing->refresh()->status);
    }

    #[Test]
    public function bookmark_creates_one_bookmark_per_listing(): void
    {
        $listing = AggregatorListing::factory()->create();

        $this->actingAs($this->user)
            ->post('/bulk-actions', [
                'entity' => 'aggregator_listing',
                'action' => 'bookmark',
                'ids' => [$listing->id],
            ])
            ->assertRedirect();

        $this->assertSame(1, Bookmark::query()->count());
        $this->assertTrue($listing->bookmarks()->exists());
    }

    #[Test]
    public function mark_closed_closes_roles(): void
    {
        $company = Company::factory()->create();
        $role = Role::factory()->create(['company_id' => $company->id, 'is_open' => true]);

        $this->actingAs($this->user)
            ->post('/bulk-actions', [
                'entity' => 'role',
                'action' => 'mark_closed',
                'ids' => [$role->id],
            ])
            ->assertRedirect();

        $this->assertFalse($role->refresh()->is_open);
    }

    #[Test]
    public function flash_carries_the_audit_log_id_for_undo(): void
    {
        $company = Company::factory()->create(['tier' => 'C']);

        $this->actingAs($this->user)
            ->post('/bulk-actions', [
                'entity' => 'company',
                'action' => 'change_tier',
                'ids' => [$company->id],
                'tier' => 'A',
            ])
            ->assertSessionHas('success');

        $log = AuditLog::query()->where('action', BulkActionExecutor::ACTION)->sole();
        $undo = session('flash.undo');
        $this->assertSame($log->id, $undo['audit_log_id']);
        $this->assertSame(1, $undo['count']);
    }

    #[Test]
    public function guests_cannot_perform_bulk_actions(): void
    {
        $company = Company::factory()->create();

        $this->post('/bulk-actions', [
            'entity' => 'company',
            'action' => 'dismiss',
            'ids' => [$company->id],
        ])->assertRedirect('/login');

        $this->assertSame('researching', $company->refresh()->status);
    }

    #[Test]
    public function action_not_allowed_for_entity_is_rejected(): void
    {
        $listing = AggregatorListing::factory()->create();

        $this->actingAs($this->user)
            ->post('/bulk-actions', [
                'entity' => 'aggregator_listing',
                'action' => 'change_tier',
                'ids' => [$listing->id],
                'tier' => 'A',
            ])
            ->assertSessionHasErrors('action');
    }

    #[Test]
    public function change_tier_requires_a_tier_param(): void
    {
        $company = Company::factory()->create();

        $this->actingAs($this->user)
            ->post('/bulk-actions', [
                'entity' => 'company',
                'action' => 'change_tier',
                'ids' => [$company->id],
            ])
            ->assertSessionHasErrors('tier');
    }

    #[Test]
    public function tag_requires_a_tag_param(): void
    {
        $company = Company::factory()->create();

        $this->actingAs($this->user)
            ->post('/bulk-actions', [
                'entity' => 'company',
                'action' => 'tag',
                'ids' => [$company->id],
            ])
            ->assertSessionHasErrors('tag');
    }

    #[Test]
    public function empty_id_set_is_rejected(): void
    {
        $this->actingAs($this->user)
            ->post('/bulk-actions', [
                'entity' => 'company',
                'action' => 'dismiss',
                'ids' => [],
            ])
            ->assertSessionHasErrors('ids');
    }

    #[Test]
    public function rows_from_another_tenant_are_not_touched(): void
    {
        $otherTenant = Tenant::factory()->create();
        $otherCompany = app(TenantContext::class)->withTenant(
            $otherTenant,
            fn () => Company::factory()->create(['tier' => 'C']),
        );

        $this->actingAs($this->user)
            ->post('/bulk-actions', [
                'entity' => 'company',
                'action' => 'change_tier',
                'ids' => [$otherCompany->id],
                'tier' => 'A',
            ])
            ->assertRedirect();

        // The other tenant's row is invisible under the current tenant scope,
        // so it is never loaded or mutated.
        $reloaded = app(TenantContext::class)->withTenant(
            $otherTenant,
            fn () => Company::query()->find($otherCompany->id),
        );
        $this->assertSame('C', $reloaded->tier);
    }
}
