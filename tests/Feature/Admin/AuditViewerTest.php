<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\User;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AuditViewerTest extends TestCase
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
    public function unauthenticated_users_are_redirected_to_login(): void
    {
        $this->get('/admin/audit')->assertRedirect('/login');
    }

    #[Test]
    public function lists_tenant_rows_newest_first_and_paginates(): void
    {
        for ($i = 0; $i < 60; $i++) {
            AuditLog::factory()->create([
                'tenant_id' => $this->tenant->id,
                'action' => 'auth.login.success',
                'created_at' => CarbonImmutable::now()->subMinutes($i),
            ]);
        }

        $response = $this->actingAs($this->user)->get('/admin/audit');

        $response->assertOk();
        $props = $response->viewData('page')['props'];
        $this->assertSame(60, $props['logs']['meta']['total']);
        $this->assertSame(50, count($props['logs']['data']));
        $this->assertSame(2, $props['logs']['meta']['last_page']);

        $first = $props['logs']['data'][0];
        $last = $props['logs']['data'][count($props['logs']['data']) - 1];
        $this->assertGreaterThanOrEqual($last['created_at'], $first['created_at']);
    }

    #[Test]
    public function null_tenant_rows_are_visible_but_other_tenants_are_hidden(): void
    {
        $otherTenant = Tenant::factory()->create();

        AuditLog::factory()->create(['tenant_id' => $this->tenant->id, 'action' => 'auth.logout']);
        AuditLog::factory()->create(['tenant_id' => null, 'action' => 'auth.login.failed', 'outcome' => 'failure']);
        AuditLog::factory()->create(['tenant_id' => $otherTenant->id, 'action' => 'auth.password.changed']);

        $response = $this->actingAs($this->user)->get('/admin/audit');

        $response->assertOk();
        $props = $response->viewData('page')['props'];
        $this->assertSame(2, $props['logs']['meta']['total']);

        $actions = array_map(static fn (array $row): string => $row['action'], $props['logs']['data']);
        $this->assertContains('auth.logout', $actions);
        $this->assertContains('auth.login.failed', $actions);
        $this->assertNotContains('auth.password.changed', $actions);
    }

    #[Test]
    public function action_filter_matches_by_prefix(): void
    {
        AuditLog::factory()->create(['tenant_id' => $this->tenant->id, 'action' => 'auth.login.success']);
        AuditLog::factory()->create(['tenant_id' => $this->tenant->id, 'action' => 'auth.login.failed', 'outcome' => 'failure']);
        AuditLog::factory()->create(['tenant_id' => $this->tenant->id, 'action' => 'bulk.change_tier']);

        $response = $this->actingAs($this->user)->get('/admin/audit?action=auth.login');

        $response->assertOk();
        $props = $response->viewData('page')['props'];
        $this->assertSame(2, $props['logs']['meta']['total']);
        $this->assertSame('auth.login', $props['filters']['action']);
    }

    #[Test]
    public function outcome_filter_round_trips(): void
    {
        AuditLog::factory()->create(['tenant_id' => $this->tenant->id, 'action' => 'auth.login.success', 'outcome' => 'success']);
        AuditLog::factory()->create(['tenant_id' => $this->tenant->id, 'action' => 'auth.login.failed', 'outcome' => 'failure']);

        $response = $this->actingAs($this->user)->get('/admin/audit?outcome=failure');

        $response->assertOk();
        $props = $response->viewData('page')['props'];
        $this->assertSame(1, $props['logs']['meta']['total']);
        $this->assertSame('failure', $props['logs']['data'][0]['outcome']);
        $this->assertSame('failure', $props['filters']['outcome']);
    }

    #[Test]
    public function date_range_filter_round_trips(): void
    {
        $now = CarbonImmutable::now();

        AuditLog::factory()->create(['tenant_id' => $this->tenant->id, 'created_at' => $now->subDays(20)]);
        AuditLog::factory()->create(['tenant_id' => $this->tenant->id, 'created_at' => $now->subDays(2)]);

        $from = $now->subDays(7)->toDateString();
        $to = $now->toDateString();
        $response = $this->actingAs($this->user)->get("/admin/audit?date_from={$from}&date_to={$to}");

        $response->assertOk();
        $props = $response->viewData('page')['props'];
        $this->assertSame(1, $props['logs']['meta']['total']);
        $this->assertSame($from, $props['filters']['date_from']);
        $this->assertSame($to, $props['filters']['date_to']);
    }

    #[Test]
    public function unknown_query_keys_are_silently_ignored(): void
    {
        AuditLog::factory()->create(['tenant_id' => $this->tenant->id]);

        $response = $this->actingAs($this->user)->get('/admin/audit?garbage=yes&outcome=banana');

        $response->assertOk();
        $props = $response->viewData('page')['props'];
        $this->assertNull($props['filters']['outcome']);
    }

    #[Test]
    public function context_does_not_leak_passwords(): void
    {
        AuditLog::factory()->create([
            'tenant_id' => $this->tenant->id,
            'action' => 'auth.login.failed',
            'outcome' => 'failure',
            'context' => ['email' => 'attacker@example.com'],
        ]);

        $response = $this->actingAs($this->user)->get('/admin/audit');

        $response->assertOk();
        $props = $response->viewData('page')['props'];
        $context = $props['logs']['data'][0]['context'];
        $this->assertArrayNotHasKey('password', $context);
        $this->assertSame('attacker@example.com', $context['email']);
    }
}
