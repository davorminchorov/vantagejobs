<?php

declare(strict_types=1);

namespace Tests\Feature\Tags;

use App\Models\Company;
use App\Models\Tag;
use App\Models\User;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class TagsTest extends TestCase
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
    public function autocomplete_returns_tenant_scoped_results_ranked_by_usage(): void
    {
        $popular = Tag::factory()->create(['name' => 'founder-i-follow', 'slug' => 'founder-i-follow']);
        $rare = Tag::factory()->create(['name' => 'founder-also', 'slug' => 'founder-also']);

        $companies = Company::factory()->count(3)->create();
        foreach ($companies as $company) {
            $company->tags()->attach($popular->id);
        }
        $companies->first()->tags()->attach($rare->id);

        // Foreign tenant tag must not appear.
        $otherTenant = Tenant::factory()->create();
        app(TenantContext::class)->withTenant($otherTenant, function (): void {
            Tag::factory()->create(['name' => 'founder-zzz', 'slug' => 'founder-zzz']);
        });

        $response = $this->actingAs($this->user)->getJson('/tags?q=founder');
        $response->assertOk();

        $tags = $response->json('tags');
        $this->assertCount(2, $tags, 'Only tenant tags are returned.');
        $this->assertSame('founder-i-follow', $tags[0]['slug']);
        $this->assertSame(3, $tags[0]['usage_count']);
        $this->assertSame('founder-also', $tags[1]['slug']);
        $this->assertSame(1, $tags[1]['usage_count']);
    }

    #[Test]
    public function autocomplete_with_no_query_returns_top_tags(): void
    {
        Tag::factory()->count(3)->create();

        $this->actingAs($this->user)
            ->getJson('/tags')
            ->assertOk()
            ->assertJsonCount(3, 'tags');
    }

    #[Test]
    public function attach_creates_a_new_tag_and_links_it_to_the_company(): void
    {
        $company = Company::factory()->create();

        $this->actingAs($this->user)
            ->from("/companies/{$company->id}")
            ->post("/companies/{$company->id}/tags", ['name' => '  Founder I Follow  '])
            ->assertRedirect("/companies/{$company->id}");

        $tag = Tag::query()->sole();
        $this->assertSame('founder-i-follow', $tag->slug);
        $this->assertSame('Founder I Follow', $tag->name);
        $this->assertSame(1, $company->tags()->count());
    }

    #[Test]
    public function attach_is_idempotent_on_repeat(): void
    {
        $company = Company::factory()->create();

        $this->actingAs($this->user)->post("/companies/{$company->id}/tags", ['name' => 'remote-friendly']);
        $this->actingAs($this->user)->post("/companies/{$company->id}/tags", ['name' => 'Remote-Friendly']);

        $this->assertSame(1, Tag::query()->count());
        $this->assertSame(1, $company->tags()->count());
        $this->assertSame(1, DB::table('taggables')->count());
    }

    #[Test]
    public function attach_reuses_existing_tag_when_slug_matches(): void
    {
        $existing = Tag::factory()->create(['name' => 'worth-reaching-out', 'slug' => 'worth-reaching-out']);
        $company = Company::factory()->create();

        $this->actingAs($this->user)->post("/companies/{$company->id}/tags", ['name' => 'WORTH REACHING OUT']);

        $this->assertSame(1, Tag::query()->count());
        $this->assertTrue($company->tags()->where('tags.id', $existing->id)->exists());
    }

    #[Test]
    public function detach_removes_the_link_but_keeps_the_tag_row(): void
    {
        $company = Company::factory()->create();
        $tag = Tag::factory()->create();
        $company->tags()->attach($tag->id);

        $this->actingAs($this->user)
            ->from("/companies/{$company->id}")
            ->delete("/companies/{$company->id}/tags/{$tag->id}")
            ->assertRedirect("/companies/{$company->id}");

        $this->assertSame(0, $company->tags()->count());
        $this->assertTrue(Tag::query()->whereKey($tag->id)->exists());
    }

    #[Test]
    public function attach_blocks_other_tenants_company(): void
    {
        $otherTenant = Tenant::factory()->create();
        $foreign = app(TenantContext::class)->withTenant(
            $otherTenant,
            fn () => Company::factory()->create(),
        );

        $this->actingAs($this->user)
            ->post("/companies/{$foreign->id}/tags", ['name' => 'sneaky'])
            ->assertNotFound();
    }

    #[Test]
    public function attach_validates_name(): void
    {
        $company = Company::factory()->create();

        $this->actingAs($this->user)
            ->from("/companies/{$company->id}")
            ->post("/companies/{$company->id}/tags", ['name' => ''])
            ->assertSessionHasErrors('name');
    }

    #[Test]
    public function show_page_renders_tags_in_props(): void
    {
        $company = Company::factory()->create();
        $tag = Tag::factory()->create(['name' => 'from-hn', 'slug' => 'from-hn']);
        $company->tags()->attach($tag->id);

        $this->actingAs($this->user)
            ->get("/companies/{$company->id}")
            ->assertInertia(fn ($page) => $page
                ->has('tags', 1)
                ->where('tags.0.slug', 'from-hn'));
    }
}
