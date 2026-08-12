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

    #[Test]
    public function touch_refreshes_mutable_fields_from_the_latest_fetch(): void
    {
        $role = Role::factory()->create([
            'company_id' => $this->company->id,
            'source' => 'greenhouse',
            'external_id' => 'gh-changed',
            'title' => 'Old Title',
            'location' => 'Old Location',
            'comp_min' => 100000,
            'comp_max' => 120000,
            'is_open' => true,
        ]);

        $plan = $this->differ->plan($this->company->roles()->get(), [
            new ParsedRole(
                externalId: 'gh-changed',
                title: 'New Title',
                location: 'Remote - US',
                isRemote: true,
                workplaceType: WorkplaceType::REMOTE,
                compMin: 150000,
                compMax: 180000,
                compCurrency: 'USD',
                compInterval: 'per-year',
                raw: ['posting' => 'updated'],
            ),
        ]);

        $counts = $this->differ->apply($this->company, AtsType::GREENHOUSE, $plan);

        $this->assertSame(['new' => 0, 'reopened' => 0, 'closed' => 0, 'touched' => 1], $counts);

        $fresh = $role->fresh();
        $this->assertSame('New Title', $fresh->title);
        $this->assertSame('Remote - US', $fresh->location);
        $this->assertTrue((bool) $fresh->is_remote);
        $this->assertSame(WorkplaceType::REMOTE, $fresh->workplace_type);
        $this->assertSame(150000, $fresh->comp_min);
        $this->assertSame(180000, $fresh->comp_max);
        $this->assertSame('USD', $fresh->comp_currency);
        $this->assertSame(['posting' => 'updated'], $fresh->raw);
        $this->assertSame(0, Event::query()->count(), 'Detail refresh must not emit any event.');
    }

    #[Test]
    public function would_mass_close_is_false_when_the_fetch_is_non_empty(): void
    {
        $existing = $this->company->roles()->get();

        $this->assertFalse($this->differ->wouldMassClose($existing, [
            new ParsedRole(externalId: 'gh-1', title: 'Backend Engineer'),
        ]));
    }

    #[Test]
    public function would_mass_close_is_false_for_an_empty_fetch_with_no_open_roles(): void
    {
        Role::factory()->create([
            'company_id' => $this->company->id,
            'source' => 'greenhouse',
            'is_open' => false,
        ]);

        $existing = $this->company->roles()->get();

        $this->assertFalse($this->differ->wouldMassClose($existing, []));
    }

    #[Test]
    public function would_mass_close_is_true_for_an_empty_fetch_with_an_open_role(): void
    {
        Role::factory()->create([
            'company_id' => $this->company->id,
            'source' => 'greenhouse',
            'is_open' => true,
        ]);

        $existing = $this->company->roles()->get();

        $this->assertTrue($this->differ->wouldMassClose($existing, []));
    }

    #[Test]
    public function ats_insert_silently_closes_the_aggregator_role_with_the_matching_title(): void
    {
        $aggregatorRole = Role::factory()->aggregator()->create([
            'company_id' => $this->company->id,
            'title' => 'Backend Engineer',
            'is_open' => true,
        ]);

        // Scoped to the ATS source, matching real scraper usage — an
        // aggregator-born role is never part of an ATS diff's "existing" set.
        $plan = $this->differ->plan($this->company->roles()->where('source', 'greenhouse')->get(), [
            new ParsedRole(externalId: 'gh-1', title: 'backend engineer'),
        ]);

        $counts = $this->differ->apply($this->company, AtsType::GREENHOUSE, $plan);

        $this->assertSame(1, $counts['new']);
        $this->assertFalse((bool) $aggregatorRole->fresh()->is_open);
        $this->assertSame(
            0,
            Event::query()->where('type', EventType::ROLE_CLOSED->value)->count(),
            'Supersede must not emit a ROLE_CLOSED event — the timeline already reads "seen on aggregator" then "new role".',
        );
    }

    #[Test]
    public function ats_insert_leaves_an_aggregator_role_with_a_different_title_open(): void
    {
        $aggregatorRole = Role::factory()->aggregator()->create([
            'company_id' => $this->company->id,
            'title' => 'Frontend Engineer',
            'is_open' => true,
        ]);

        $plan = $this->differ->plan($this->company->roles()->where('source', 'greenhouse')->get(), [
            new ParsedRole(externalId: 'gh-1', title: 'Backend Engineer'),
        ]);

        $this->differ->apply($this->company, AtsType::GREENHOUSE, $plan);

        $this->assertTrue((bool) $aggregatorRole->fresh()->is_open);
    }

    #[Test]
    public function ats_insert_does_not_supersede_an_already_closed_aggregator_role(): void
    {
        Role::factory()->aggregator()->create([
            'company_id' => $this->company->id,
            'title' => 'Backend Engineer',
            'is_open' => false,
        ]);

        $plan = $this->differ->plan($this->company->roles()->where('source', 'greenhouse')->get(), [
            new ParsedRole(externalId: 'gh-1', title: 'Backend Engineer'),
        ]);

        // Must not throw or double-close; the query only targets is_open=true.
        $counts = $this->differ->apply($this->company, AtsType::GREENHOUSE, $plan);

        $this->assertSame(1, $counts['new']);
    }
}
