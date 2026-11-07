<?php

declare(strict_types=1);

namespace Tests\Feature\Applications;

use App\Models\User;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class LegacyRedirectsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::factory()->create();
        app(TenantContext::class)->setCurrent($tenant);
        $this->user = User::factory()->create(['tenant_id' => $tenant->id]);
    }

    #[Test]
    public function pipeline_redirects_to_applications(): void
    {
        $this->actingAs($this->user)->get('/pipeline')->assertRedirect('/applications');
    }

    #[Test]
    public function follow_ups_redirects_to_the_attention_view(): void
    {
        $this->actingAs($this->user)->get('/follow-ups')->assertRedirect('/applications?view=attention');
    }

    #[Test]
    public function calendar_redirects_to_the_upcoming_view(): void
    {
        $this->actingAs($this->user)->get('/calendar')->assertRedirect('/applications?view=upcoming');
    }

    #[Test]
    public function calendar_carries_the_past_flag(): void
    {
        $this->actingAs($this->user)->get('/calendar?past=1')->assertRedirect('/applications?view=upcoming&past=1');
    }

    #[Test]
    public function role_page_redirects_to_the_selected_application(): void
    {
        $this->actingAs($this->user)->get('/pipeline/roles/42')->assertRedirect('/applications?role=42');
    }

    #[Test]
    public function old_urls_require_authentication(): void
    {
        foreach (['/pipeline', '/follow-ups', '/calendar', '/pipeline/roles/1'] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
    }

    #[Test]
    public function route_names_are_kept(): void
    {
        $this->assertSame('/pipeline', route('pipeline.index', [], false));
        $this->assertSame('/follow-ups', route('follow-ups.index', [], false));
        $this->assertSame('/calendar', route('calendar.index', [], false));
        $this->assertSame('/pipeline/roles/7', route('pipeline.roles.show', 7, false));
    }
}
