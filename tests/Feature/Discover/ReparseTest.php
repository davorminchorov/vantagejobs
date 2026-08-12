<?php

declare(strict_types=1);

namespace Tests\Feature\Discover;

use App\Enums\AggregatorListingStatus;
use App\Enums\AggregatorSourceType;
use App\Models\AggregatorListing;
use App\Models\AggregatorSource;
use App\Models\ParseCorrection;
use App\Models\User;
use App\Scrapers\Aggregator\CompanyKeyNormalizer;
use App\Scrapers\Aggregator\RoleHasher;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ReparseTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    private AggregatorSource $remotive;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->setCurrent($this->tenant);
        $this->user = User::factory()->create();
        $this->remotive = AggregatorSource::factory()->ofType(AggregatorSourceType::REMOTIVE)->create();
    }

    /**
     * Realistic Remotive job shape -- the pipeline already keys on these
     * field names, so re-parsing yields predictable values.
     *
     * @return array<string, mixed>
     */
    private function remotiveRawRecord(): array
    {
        return [
            'id' => 999001,
            'url' => 'https://remotive.com/jobs/999001',
            'title' => 'Senior Backend Engineer',
            'company_name' => 'Stripe',
            'salary' => '$180,000 - $240,000',
            'job_type' => 'full_time',
            'candidate_required_location' => 'Worldwide',
            'tags' => ['python', 'postgres', 'kubernetes'],
            'publication_date' => '2026-05-01T12:00:00',
            'description' => '<p>We are hiring a senior backend engineer to work on payments infrastructure.</p>',
        ];
    }

    #[Test]
    public function reparse_replaces_parsed_jsonb_and_denormalized_columns(): void
    {
        $listing = AggregatorListing::factory()->create([
            'source_id' => $this->remotive->id,
            'external_id' => '999001',
            'raw' => $this->remotiveRawRecord(),
            // Wipe denormalized columns so we can prove the re-parse wrote.
            'parsed_company_name' => null,
            'parsed_role_title' => null,
            'parsed' => [],
        ]);

        $this->actingAs($this->user)
            ->post("/discover/{$listing->id}/reparse")
            ->assertRedirect();

        $fresh = $listing->fresh();
        $this->assertSame('Stripe', $fresh->parsed_company_name);
        $this->assertSame('Senior Backend Engineer', $fresh->parsed_role_title);
        $this->assertIsArray($fresh->parsed);
        $this->assertArrayHasKey('company_name', $fresh->parsed);
    }

    #[Test]
    public function reparse_preserves_existing_corrections_by_default(): void
    {
        $listing = AggregatorListing::factory()->create([
            'source_id' => $this->remotive->id,
            'external_id' => '999001',
            'raw' => $this->remotiveRawRecord(),
            'parsed_company_name' => 'Stripe',
            'parsed_role_title' => 'Senior Backend Engineer',
            'parsed' => [],
            'status' => AggregatorListingStatus::NEW,
        ]);

        // Existing user correction: the role title is actually Staff, not Senior.
        $this->actingAs($this->user)->post("/discover/{$listing->id}/corrections", [
            'field' => 'role_title',
            'new_value' => 'Staff Backend Engineer',
        ])->assertRedirect();

        $this->assertSame('Staff Backend Engineer', $listing->fresh()->parsed_role_title);
        $this->assertSame(1, ParseCorrection::query()->where('listing_id', $listing->id)->count());

        // Now re-parse. The parser produces "Senior Backend Engineer" from
        // the raw record, but the correction overlay must win.
        $this->actingAs($this->user)
            ->post("/discover/{$listing->id}/reparse")
            ->assertRedirect();

        $fresh = $listing->fresh();
        $this->assertSame('Staff Backend Engineer', $fresh->parsed_role_title);

        $slot = $fresh->parsedField('role_title');
        $this->assertSame('Staff Backend Engineer', $slot['value']);
        $this->assertEquals(1.0, $slot['confidence']);
        $this->assertSame('explicit', $slot['source']);
        $this->assertSame('user_correction', $slot['evidence']);

        // Audit row stays a single entry -- a re-parse is not a new
        // correction event.
        $this->assertSame(1, ParseCorrection::query()->where('listing_id', $listing->id)->count());
    }

    #[Test]
    public function force_skips_the_correction_overlay(): void
    {
        $listing = AggregatorListing::factory()->create([
            'source_id' => $this->remotive->id,
            'external_id' => '999001',
            'raw' => $this->remotiveRawRecord(),
            'parsed_role_title' => 'Senior Backend Engineer',
            'parsed' => [],
        ]);

        $this->actingAs($this->user)->post("/discover/{$listing->id}/corrections", [
            'field' => 'role_title',
            'new_value' => 'Staff Backend Engineer',
        ])->assertRedirect();

        $this->actingAs($this->user)
            ->post("/discover/{$listing->id}/reparse?force=1")
            ->assertRedirect();

        $fresh = $listing->fresh();
        // With force=1, the parser's view wins over the user's correction.
        $this->assertSame('Senior Backend Engineer', $fresh->parsed_role_title);
        // The audit row is preserved -- only the live state changes.
        $this->assertSame(1, ParseCorrection::query()->where('listing_id', $listing->id)->count());
    }

    #[Test]
    public function reparse_recomputes_normalized_keys_and_role_hash(): void
    {
        // normalized_company_key / normalized_role_key / role_hash are set
        // once at ingest and otherwise never touched -- seed stale values
        // (as if the listing was ingested before a parser or normalizer
        // change) and prove reparse brings them back in line with the
        // freshly-parsed company_name / role_title.
        $listing = AggregatorListing::factory()->create([
            'source_id' => $this->remotive->id,
            'external_id' => '999001',
            'raw' => $this->remotiveRawRecord(),
            'parsed' => [],
            'normalized_company_key' => 'stale-key',
            'normalized_role_key' => 'stale-role',
            'role_hash' => 'stale-hash',
        ]);

        $this->actingAs($this->user)
            ->post("/discover/{$listing->id}/reparse")
            ->assertRedirect();

        $fresh = $listing->fresh();
        $normalizer = $this->app->make(CompanyKeyNormalizer::class);
        $hasher = $this->app->make(RoleHasher::class);

        $expectedCompanyKey = $normalizer->normalize($fresh->parsed_company_name);
        $expectedRoleKey = $normalizer->normalize($fresh->parsed_role_title);

        $this->assertNotSame('stale-key', $fresh->normalized_company_key);
        $this->assertSame($expectedCompanyKey, $fresh->normalized_company_key);
        $this->assertSame($expectedRoleKey, $fresh->normalized_role_key);
        $this->assertNotSame('stale-hash', $fresh->role_hash);
        $this->assertSame(
            $hasher->hash($expectedCompanyKey, $expectedRoleKey, $fresh->location_bucket),
            $fresh->role_hash,
        );
    }

    #[Test]
    public function reparse_is_idempotent(): void
    {
        $listing = AggregatorListing::factory()->create([
            'source_id' => $this->remotive->id,
            'external_id' => '999001',
            'raw' => $this->remotiveRawRecord(),
            'parsed' => [],
        ]);

        $this->actingAs($this->user)->post("/discover/{$listing->id}/reparse")->assertRedirect();
        $first = $listing->fresh()->toArray();

        $this->actingAs($this->user)->post("/discover/{$listing->id}/reparse")->assertRedirect();
        $second = $listing->fresh()->toArray();

        // updated_at moves between requests; ignore it for the equality check.
        unset($first['updated_at'], $second['updated_at']);
        $this->assertSame($first, $second);
    }

    #[Test]
    public function reparse_requires_authentication(): void
    {
        $listing = AggregatorListing::factory()->create([
            'source_id' => $this->remotive->id,
            'raw' => $this->remotiveRawRecord(),
        ]);

        $this->post("/discover/{$listing->id}/reparse")->assertRedirect('/login');
    }
}
