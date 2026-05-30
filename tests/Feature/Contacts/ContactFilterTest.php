<?php

declare(strict_types=1);

namespace Tests\Feature\Contacts;

use App\Models\Company;
use App\Models\Contact;
use App\Models\User;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ContactFilterTest extends TestCase
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
    public function filters_by_company(): void
    {
        $acme = Company::factory()->create(['name' => 'Acme']);
        $other = Company::factory()->create(['name' => 'Other']);

        Contact::factory()->create(['name' => 'At Acme', 'company_id' => $acme->id]);
        Contact::factory()->create(['name' => 'At Other', 'company_id' => $other->id]);

        $this->actingAs($this->user)
            ->get("/contacts?company_id={$acme->id}")
            ->assertInertia(fn ($page) => $page
                ->has('contacts.data', 1)
                ->where('contacts.data.0.name', 'At Acme'));
    }

    #[Test]
    public function filters_by_role_title_substring(): void
    {
        Contact::factory()->create(['name' => 'Director Person', 'role_title' => 'Engineering Director']);
        Contact::factory()->create(['name' => 'IC Person', 'role_title' => 'Software Engineer']);

        $this->actingAs($this->user)
            ->get('/contacts?role_title=Director')
            ->assertInertia(fn ($page) => $page
                ->has('contacts.data', 1)
                ->where('contacts.data.0.name', 'Director Person'));
    }

    #[Test]
    public function filters_by_last_touch_never(): void
    {
        Contact::factory()->create(['name' => 'Untouched', 'last_touch' => null]);
        Contact::factory()->touchedDaysAgo(2)->create(['name' => 'Touched']);

        $this->actingAs($this->user)
            ->get('/contacts?last_touch=never')
            ->assertInertia(fn ($page) => $page
                ->has('contacts.data', 1)
                ->where('contacts.data.0.name', 'Untouched'));
    }

    #[Test]
    public function filters_by_last_touch_older_than_30d(): void
    {
        Contact::factory()->touchedDaysAgo(60)->create(['name' => 'Stale']);
        Contact::factory()->touchedDaysAgo(2)->create(['name' => 'Fresh']);

        $this->actingAs($this->user)
            ->get('/contacts?last_touch=older_than_30d')
            ->assertInertia(fn ($page) => $page
                ->has('contacts.data', 1)
                ->where('contacts.data.0.name', 'Stale'));
    }

    #[Test]
    public function search_matches_name_email_or_role(): void
    {
        Contact::factory()->create(['name' => 'Searchable Sam', 'email' => 'sam@example.com', 'role_title' => 'CTO']);
        Contact::factory()->create(['name' => 'Unrelated', 'email' => 'nobody@example.com', 'role_title' => 'Analyst']);

        $this->actingAs($this->user)
            ->get('/contacts?q=sam@example.com')
            ->assertInertia(fn ($page) => $page
                ->has('contacts.data', 1)
                ->where('contacts.data.0.name', 'Searchable Sam'));
    }

    #[Test]
    public function unknown_filter_values_are_ignored(): void
    {
        Contact::factory()->count(2)->create();

        $this->actingAs($this->user)
            ->get('/contacts?last_touch=garbage&company_id=notanumber')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('contacts.data', 2));
    }

    #[Test]
    public function index_only_shows_current_tenant_contacts(): void
    {
        Contact::factory()->create(['name' => 'Mine']);

        $otherTenant = Tenant::factory()->create();
        app(TenantContext::class)->withTenant(
            $otherTenant,
            fn () => Contact::factory()->create(['name' => 'Theirs']),
        );

        $this->actingAs($this->user)
            ->get('/contacts')
            ->assertInertia(fn ($page) => $page
                ->has('contacts.data', 1)
                ->where('contacts.data.0.name', 'Mine'));
    }
}
