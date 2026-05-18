<?php

declare(strict_types=1);

namespace Tests\Feature\Discover;

use App\Enums\AggregatorSourceType;
use App\Models\AggregatorListing;
use App\Models\AggregatorSource;
use App\Models\Bookmark;
use App\Models\DismissedCompany;
use App\Models\User;
use App\Scrapers\Aggregator\HackerNews\Requests\FetchThreadRequest;
use App\Scrapers\Aggregator\HackerNews\Requests\FindLatestThreadRequest;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;
use Tests\TestCase;

final class SourcesAndBookmarksTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->setCurrent($this->tenant);
        $this->user = User::factory()->create();
    }

    /* --------------------------------------------------------------
     | /discover/sources
     * -------------------------------------------------------------- */

    #[Test]
    public function sources_index_lists_sources_and_rules(): void
    {
        AggregatorSource::factory()->ofType(AggregatorSourceType::HACKERNEWS)->create();
        DismissedCompany::factory()->auto()->create(['display_name' => 'Globex']);

        $response = $this->actingAs($this->user)->get('/discover/sources');

        $response->assertOk();
        $props = $response->viewData('page')['props'];
        $this->assertCount(1, $props['sources']);
        $this->assertCount(1, $props['rules']);
    }

    #[Test]
    public function toggle_flips_enabled(): void
    {
        $source = AggregatorSource::factory()->ofType(AggregatorSourceType::HACKERNEWS)->create(['enabled' => true]);

        $this->actingAs($this->user)
            ->post("/discover/sources/{$source->id}/toggle")
            ->assertRedirect();

        $this->assertFalse($source->fresh()->enabled);

        $this->actingAs($this->user)
            ->post("/discover/sources/{$source->id}/toggle")
            ->assertRedirect();

        $this->assertTrue($source->fresh()->enabled);
    }

    #[Test]
    public function run_now_dispatches_orchestrator_and_flashes_status(): void
    {
        $source = AggregatorSource::factory()->ofType(AggregatorSourceType::HACKERNEWS)->create();

        Saloon::fake([
            FindLatestThreadRequest::class => MockResponse::make(['hits' => []], 200),
        ]);

        $response = $this->actingAs($this->user)
            ->from('/discover/sources')
            ->post("/discover/sources/{$source->id}/run");

        $response->assertRedirect();
        $flash = session('flash');
        $this->assertIsArray($flash);
        // No-thread-found surfaces as a per-run error captured into errors[];
        // the orchestrator tags that as 'partial' (run completed, with errors).
        // Flash level is 'info' for everything except orchestrator-level throws.
        $this->assertSame('info', $flash['level']);
        $this->assertStringContainsString('status=partial', $flash['message']);
    }

    #[Test]
    public function revoke_auto_dismiss_clears_the_flag_but_keeps_history(): void
    {
        $rule = DismissedCompany::factory()->auto()->create([
            'display_name' => 'Globex',
            'dismiss_count' => 5,
        ]);

        $this->actingAs($this->user)
            ->post("/discover/auto-dismiss-rules/{$rule->id}/revoke")
            ->assertRedirect();

        $fresh = $rule->fresh();
        $this->assertFalse($fresh->auto_dismiss);
        $this->assertSame(5, $fresh->dismiss_count);
    }

    /* --------------------------------------------------------------
     | Bookmarks
     * -------------------------------------------------------------- */

    #[Test]
    public function bookmarking_a_listing_creates_a_30_day_bookmark(): void
    {
        $source = AggregatorSource::factory()->ofType(AggregatorSourceType::HACKERNEWS)->create();
        $listing = AggregatorListing::factory()->create(['source_id' => $source->id]);

        $this->actingAs($this->user)
            ->post("/discover/{$listing->id}/bookmark")
            ->assertRedirect();

        $bookmark = Bookmark::query()->sole();
        $this->assertSame('aggregator_listing', $bookmark->bookmarkable_type);
        $this->assertSame($listing->id, (int) $bookmark->bookmarkable_id);
        $this->assertTrue($bookmark->expires_at->greaterThan(CarbonImmutable::now()->addDays(29)));
        $this->assertTrue($bookmark->expires_at->lessThan(CarbonImmutable::now()->addDays(31)));
    }

    #[Test]
    public function bookmarking_twice_is_idempotent_and_extends_expiry(): void
    {
        $source = AggregatorSource::factory()->ofType(AggregatorSourceType::HACKERNEWS)->create();
        $listing = AggregatorListing::factory()->create(['source_id' => $source->id]);

        // First bookmark
        $this->actingAs($this->user)->post("/discover/{$listing->id}/bookmark");
        $first = Bookmark::query()->sole();
        $firstExpiry = $first->expires_at;

        // Manually warn it, then re-bookmark
        $first->expires_warned_at = CarbonImmutable::now();
        $first->save();

        $this->actingAs($this->user)->post("/discover/{$listing->id}/bookmark");

        $this->assertSame(1, Bookmark::query()->count());
        $second = Bookmark::query()->sole();
        $this->assertTrue($second->expires_at->greaterThanOrEqualTo($firstExpiry));
        $this->assertNull($second->expires_warned_at);
    }

    #[Test]
    public function bookmarks_index_lists_bookmarked_listings(): void
    {
        $source = AggregatorSource::factory()->ofType(AggregatorSourceType::HACKERNEWS)->create();
        $listing = AggregatorListing::factory()->create([
            'source_id' => $source->id,
            'parsed_company_name' => 'Acme Corp',
            'parsed_role_title' => 'Senior Engineer',
        ]);
        Bookmark::factory()->create([
            'bookmarkable_type' => 'aggregator_listing',
            'bookmarkable_id' => $listing->id,
        ]);

        $response = $this->actingAs($this->user)->get('/bookmarks');

        $response->assertOk();
        $props = $response->viewData('page')['props'];
        $data = $props['bookmarks']['data'];
        $this->assertCount(1, $data);
        $this->assertSame('Acme Corp', $data[0]['listing']['parsed_company_name']);
    }

    #[Test]
    public function bookmarks_index_accepts_sort_options(): void
    {
        $response = $this->actingAs($this->user)->get('/bookmarks?sort=expires');
        $response->assertOk();
        $this->assertSame('expires', $response->viewData('page')['props']['sort']);

        $response = $this->actingAs($this->user)->get('/bookmarks?sort=nonsense');
        $this->assertSame('added', $response->viewData('page')['props']['sort']);
    }

    #[Test]
    public function deleting_a_bookmark_removes_it(): void
    {
        $source = AggregatorSource::factory()->ofType(AggregatorSourceType::HACKERNEWS)->create();
        $listing = AggregatorListing::factory()->create(['source_id' => $source->id]);
        $bookmark = Bookmark::factory()->create([
            'bookmarkable_type' => 'aggregator_listing',
            'bookmarkable_id' => $listing->id,
        ]);

        $this->actingAs($this->user)
            ->delete("/bookmarks/{$bookmark->id}")
            ->assertRedirect();

        $this->assertSame(0, Bookmark::query()->count());
    }

    /* --------------------------------------------------------------
     | Auth gating
     * -------------------------------------------------------------- */

    #[Test]
    public function all_step_16_routes_require_auth(): void
    {
        $this->get('/discover/sources')->assertRedirect('/login');
        $this->post('/discover/sources/1/toggle')->assertRedirect('/login');
        $this->post('/discover/sources/1/run')->assertRedirect('/login');
        $this->post('/discover/auto-dismiss-rules/1/revoke')->assertRedirect('/login');
        $this->post('/discover/1/bookmark')->assertRedirect('/login');
        $this->get('/bookmarks')->assertRedirect('/login');
        $this->delete('/bookmarks/1')->assertRedirect('/login');
    }
}
