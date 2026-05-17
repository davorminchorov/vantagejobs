<?php

declare(strict_types=1);

namespace Tests\Feature\Schema;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Event;
use App\Models\Note;
use App\Models\Role;
use App\Models\Source;
use App\Models\User;
use App\Tenancy\RequiresTenantContextException;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ModelTenancyTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;

    private Tenant $tenantB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = Tenant::factory()->create(['slug' => 'tenant-a']);
        $this->tenantB = Tenant::factory()->create(['slug' => 'tenant-b']);

        app(TenantContext::class)->setCurrent($this->tenantA);
    }

    #[Test]
    public function source_auto_fills_tenant_id_from_context(): void
    {
        $source = Source::factory()->create(['name' => 'greenhouse', 'type' => 'ats']);

        $this->assertSame($this->tenantA->id, $source->tenant_id);
    }

    #[Test]
    public function company_auto_fills_tenant_id_and_scopes_queries(): void
    {
        Company::factory()->create(['name' => 'Tenant A Co']);

        app(TenantContext::class)->withTenant($this->tenantB, function (): void {
            Company::factory()->create(['name' => 'Tenant B Co']);
        });

        $this->assertSame(1, Company::query()->count());
        $this->assertSame('Tenant A Co', Company::query()->sole()->name);

        $this->assertSame(2, Company::withoutTenantScope()->count());
    }

    #[Test]
    public function role_inherits_tenant_from_parent_company(): void
    {
        $company = Company::factory()->create();
        $role = Role::factory()->create(['company_id' => $company->id]);

        $this->assertSame($this->tenantA->id, $role->tenant_id);
        $this->assertSame($company->id, $role->company_id);
    }

    #[Test]
    public function event_auto_fills_tenant_id(): void
    {
        $company = Company::factory()->create();
        $event = Event::factory()->create(['company_id' => $company->id]);

        $this->assertSame($this->tenantA->id, $event->tenant_id);
        $this->assertNull($event->updated_at, 'events are append-only and have no updated_at');
    }

    #[Test]
    public function note_auto_fills_tenant_id(): void
    {
        $company = Company::factory()->create();
        $note = Note::factory()->create(['company_id' => $company->id]);

        $this->assertSame($this->tenantA->id, $note->tenant_id);
    }

    #[Test]
    public function user_auto_fills_tenant_id_and_two_factor_columns_persist(): void
    {
        $user = User::factory()->create();

        $this->assertSame($this->tenantA->id, $user->tenant_id);
        $this->assertSame('Europe/Skopje', $user->timezone);
    }

    #[Test]
    public function audit_log_can_be_created_without_tenant_context(): void
    {
        app(TenantContext::class)->setCurrent(null);

        $log = AuditLog::factory()->create([
            'action' => 'auth.login.failed',
            'outcome' => 'failure',
        ]);

        $this->assertNull($log->tenant_id);
        $this->assertSame('auth.login.failed', $log->action);
    }

    #[Test]
    public function audit_log_can_carry_an_explicit_tenant_id_without_belongstotenant_trait(): void
    {
        $log = AuditLog::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'action' => 'auth.login.success',
        ]);

        $this->assertSame($this->tenantA->id, $log->tenant_id);
    }

    #[Test]
    public function creating_a_tenant_scoped_model_without_context_throws(): void
    {
        app(TenantContext::class)->setCurrent(null);

        $this->expectException(RequiresTenantContextException::class);

        Source::factory()->create(['name' => 'greenhouse', 'type' => 'ats']);
    }

    #[Test]
    public function company_relations_load_tenant_scoped(): void
    {
        $company = Company::factory()->create();
        Role::factory()->count(2)->create(['company_id' => $company->id]);
        Event::factory()->count(3)->create(['company_id' => $company->id]);
        Note::factory()->count(1)->create(['company_id' => $company->id]);

        $this->assertCount(2, $company->roles);
        $this->assertCount(3, $company->events);
        $this->assertCount(1, $company->notes);
    }
}
