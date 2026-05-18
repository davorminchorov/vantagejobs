<?php

declare(strict_types=1);

namespace Tests\Feature\Scrapers;

use App\Enums\AtsType;
use App\Enums\EventType;
use App\Enums\WorkplaceType;
use App\Models\Company;
use App\Models\Event;
use App\Models\Role;
use App\Scrapers\ParsedRole;
use App\Scrapers\RoleDiffer;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class RoleDifferTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private RoleDiffer $differ;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->setCurrent($this->tenant);

        $this->company = Company::factory()->create(['ats_type' => 'greenhouse']);
        $this->differ = new RoleDiffer();
    }

    #[Test]
    public function inserts_new_roles_and_emits_new_role_event(): void
    {
        $plan = $this->differ->plan($this->company->roles, [
            new ParsedRole(externalId: 'gh-1', title: 'Backend Engineer', workplaceType: WorkplaceType::REMOTE),
        ]);

        $counts = $this->differ->apply($this->company, AtsType::GREENHOUSE, $plan);

        $this->assertSame(['new' => 1, 'reopened' => 0, 'closed' => 0, 'touched' => 0], $counts);
        $this->assertDatabaseHas('roles', [
            'company_id' => $this->company->id,
            'source' => 'greenhouse',
            'external_id' => 'gh-1',
            'is_open' => true,
        ]);
        $this->assertSame(1, Event::query()->where('type', EventType::NEW_ROLE->value)->count());
    }

    #[Test]
    public function closes_roles_that_disappear_from_fetch_and_emits_closed_event(): void
    {
        $role = Role::factory()->create([
            'company_id' => $this->company->id,
            'source' => 'greenhouse',
            'external_id' => 'gh-keep-1',
            'is_open' => true,
        ]);

        $plan = $this->differ->plan($this->company->roles()->get(), [
            // gh-keep-1 not in fetched list anymore
        ]);

        $counts = $this->differ->apply($this->company, AtsType::GREENHOUSE, $plan);

        $this->assertSame(['new' => 0, 'reopened' => 0, 'closed' => 1, 'touched' => 0], $counts);
        $this->assertFalse((bool) $role->fresh()->is_open);
        $this->assertSame(1, Event::query()->where('type', EventType::ROLE_CLOSED->value)->count());
    }

    #[Test]
    public function reopens_previously_closed_role_when_it_reappears(): void
    {
        $role = Role::factory()->create([
            'company_id' => $this->company->id,
            'source' => 'greenhouse',
            'external_id' => 'gh-boomerang',
            'is_open' => false,
        ]);

        $plan = $this->differ->plan($this->company->roles()->get(), [
            new ParsedRole(externalId: 'gh-boomerang', title: 'Senior Engineer'),
        ]);

        $counts = $this->differ->apply($this->company, AtsType::GREENHOUSE, $plan);

        $this->assertSame(['new' => 0, 'reopened' => 1, 'closed' => 0, 'touched' => 1], $counts);
        $this->assertTrue((bool) $role->fresh()->is_open);
        $this->assertSame(1, Event::query()->where('type', EventType::ROLE_REOPENED->value)->count());
    }

    #[Test]
    public function touches_still_open_roles_without_emitting_events(): void
    {
        $role = Role::factory()->create([
            'company_id' => $this->company->id,
            'source' => 'greenhouse',
            'external_id' => 'gh-steady',
            'is_open' => true,
            'last_seen_at' => now()->subDays(7),
        ]);
        $originalSeenAt = $role->last_seen_at;

        $plan = $this->differ->plan($this->company->roles()->get(), [
            new ParsedRole(externalId: 'gh-steady', title: 'Staff Engineer'),
        ]);

        $counts = $this->differ->apply($this->company, AtsType::GREENHOUSE, $plan);

        $this->assertSame(['new' => 0, 'reopened' => 0, 'closed' => 0, 'touched' => 1], $counts);
        $this->assertTrue($role->fresh()->last_seen_at->greaterThan($originalSeenAt));
        $this->assertSame(0, Event::query()->count(), 'Touch must not emit any event.');
    }

    #[Test]
    public function second_apply_against_unchanged_fetch_is_a_no_op_for_events(): void
    {
        $parsed = [
            new ParsedRole(externalId: 'gh-1', title: 'Backend Engineer'),
            new ParsedRole(externalId: 'gh-2', title: 'Frontend Engineer'),
        ];

        $firstPlan = $this->differ->plan($this->company->roles()->get(), $parsed);
        $first = $this->differ->apply($this->company, AtsType::GREENHOUSE, $firstPlan);
        $this->assertSame(2, $first['new']);
        $this->assertSame(2, Event::query()->where('type', EventType::NEW_ROLE->value)->count());

        // Re-run with identical fetched payload.
        $secondPlan = $this->differ->plan($this->company->roles()->get(), $parsed);
        $second = $this->differ->apply($this->company, AtsType::GREENHOUSE, $secondPlan);

        $this->assertSame(['new' => 0, 'reopened' => 0, 'closed' => 0, 'touched' => 2], $second);
        $this->assertSame(2, Event::query()->count(), 'Idempotent: re-running must not duplicate events.');
    }

    #[Test]
    public function mixed_plan_inserts_closes_and_touches_in_one_apply(): void
    {
        Role::factory()->create([
            'company_id' => $this->company->id,
            'source' => 'greenhouse',
            'external_id' => 'gh-staying',
            'is_open' => true,
        ]);
        $closing = Role::factory()->create([
            'company_id' => $this->company->id,
            'source' => 'greenhouse',
            'external_id' => 'gh-going-away',
            'is_open' => true,
        ]);

        $plan = $this->differ->plan($this->company->roles()->get(), [
            new ParsedRole(externalId: 'gh-staying', title: 'Existing role'),
            new ParsedRole(externalId: 'gh-new', title: 'Newly-posted role'),
        ]);

        $counts = $this->differ->apply($this->company, AtsType::GREENHOUSE, $plan);

        $this->assertSame(['new' => 1, 'reopened' => 0, 'closed' => 1, 'touched' => 1], $counts);
        $this->assertFalse((bool) $closing->fresh()->is_open);
    }
}
