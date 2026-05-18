<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Parser;

use App\Models\User;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ReparseAllControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::factory()->create();
        app(TenantContext::class)->setCurrent($tenant);
        $this->user = User::factory()->create();
    }

    #[Test]
    public function rejects_without_confirm_phrase(): void
    {
        $response = $this->actingAs($this->user)
            ->from('/admin/parser')
            ->post('/admin/parser/reparse-all', []);

        $response->assertRedirect('/admin/parser');
        $response->assertSessionHas('error');
    }

    #[Test]
    public function rejects_partial_confirmation(): void
    {
        $response = $this->actingAs($this->user)
            ->from('/admin/parser')
            ->post('/admin/parser/reparse-all', ['confirm' => 'yes']);

        $response->assertRedirect('/admin/parser');
        $response->assertSessionHas('error');
    }

    #[Test]
    public function dispatches_command_with_correct_phrase(): void
    {
        Artisan::command('parser:reparse-all', function (): int {
            // overridden in-test; the real stub also returns 0.
            return 0;
        });

        $response = $this->actingAs($this->user)
            ->from('/admin/parser')
            ->post('/admin/parser/reparse-all', ['confirm' => 'YES']);

        $response->assertRedirect('/admin/parser');
        $response->assertSessionHas('success');
    }

    #[Test]
    public function rollup_button_runs_command(): void
    {
        $response = $this->actingAs($this->user)
            ->from('/admin/parser')
            ->post('/admin/parser/rollup');

        $response->assertRedirect('/admin/parser');
        $response->assertSessionHas('success');
    }
}
