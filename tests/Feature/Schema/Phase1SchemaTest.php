<?php

declare(strict_types=1);

namespace Tests\Feature\Schema;

use App\Enums\WorkplaceType;
use App\Models\Company;
use App\Models\Role;
use App\Models\ScrapeRun;
use App\Models\Tag;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class Phase1SchemaTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['slug' => 'phase-1']);
        app(TenantContext::class)->setCurrent($this->tenant);
    }

    #[Test]
    public function morph_map_resolves_short_keys_only(): void
    {
        $map = Relation::morphMap();

        $this->assertSame(Company::class, $map['company'] ?? null);
        $this->assertSame(Role::class, $map['role'] ?? null);
    }

    #[Test]
    public function tagging_a_company_writes_the_morph_alias_not_a_fqcn(): void
    {
        $company = Company::factory()->create();
        $tag = Tag::factory()->create();

        $company->tags()->attach($tag);

        $row = DB::table('taggables')->where('tag_id', $tag->id)->sole();
        $this->assertSame('company', $row->taggable_type);
    }

    #[Test]
    public function tagging_a_role_writes_the_role_alias(): void
    {
        $role = Role::factory()->create(['company_id' => Company::factory()]);
        $tag = Tag::factory()->create();

        $role->tags()->attach($tag);

        $this->assertSame('role', DB::table('taggables')->where('tag_id', $tag->id)->value('taggable_type'));
    }

    #[Test]
    public function tag_slug_normalization_lowercases_and_hyphenates(): void
    {
        $this->assertSame('founder-i-follow', Tag::normalizeSlug('  Founder I Follow  '));
        $this->assertSame('from-hn', Tag::normalizeSlug('FROM HN'));
    }

    #[Test]
    public function role_workplace_type_casts_to_enum(): void
    {
        $role = Role::factory()->create([
            'company_id' => Company::factory(),
            'workplace_type' => 'hybrid',
        ]);

        $this->assertSame(WorkplaceType::HYBRID, $role->fresh()->workplace_type);
    }

    #[Test]
    public function role_raw_payload_casts_as_array(): void
    {
        $role = Role::factory()->create([
            'company_id' => Company::factory(),
            'raw' => ['workplaceType' => 'remote', 'salaryRange' => ['min' => 100, 'max' => 200]],
        ]);

        $this->assertSame('remote', $role->fresh()->raw['workplaceType']);
    }

    #[Test]
    public function scrape_run_auto_fills_tenant_id(): void
    {
        $run = ScrapeRun::factory()->create([
            'company_id' => Company::factory(),
        ]);

        $this->assertSame($this->tenant->id, $run->tenant_id);
        $this->assertSame('completed', $run->status);
    }

    #[Test]
    public function companies_fts_index_returns_a_match(): void
    {
        Company::factory()->create(['name' => 'Acme Robotics']);
        Company::factory()->create(['name' => 'Globex Industries']);

        $hits = DB::table('companies')
            ->whereRaw("search @@ plainto_tsquery('english', ?)", ['robotics'])
            ->pluck('name');

        $this->assertCount(1, $hits);
        $this->assertSame('Acme Robotics', $hits->first());
    }

    #[Test]
    public function roles_fts_index_returns_a_match(): void
    {
        $company = Company::factory()->create();
        Role::factory()->create([
            'company_id' => $company->id,
            'title' => 'Staff Platform Engineer',
            'team' => 'Infrastructure',
        ]);

        $hits = DB::table('roles')
            ->whereRaw("search @@ plainto_tsquery('english', ?)", ['platform infrastructure'])
            ->count();

        $this->assertSame(1, $hits);
    }
}
