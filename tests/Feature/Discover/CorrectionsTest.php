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

final class CorrectionsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    private AggregatorSource $source;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->setCurrent($this->tenant);

        $this->user = User::factory()->create();
        $this->source = AggregatorSource::factory()->ofType(AggregatorSourceType::HACKERNEWS)->create();
    }

    #[Test]
    public function corrects_role_title_and_writes_audit_row(): void
    {
        $listing = AggregatorListing::factory()->create([
            'source_id' => $this->source->id,
            'status' => AggregatorListingStatus::NEW,
            'parsed_role_title' => 'Software Engineer',
            'parsed' => [
                'role_title' => [
                    'value' => 'Software Engineer',
                    'confidence' => 0.6,
                    'source' => 'inferred',
                    'evidence' => 'first-line token',
                ],
            ],
        ]);

        $this->actingAs($this->user)
            ->post("/discover/{$listing->id}/corrections", [
                'field' => 'role_title',
                'new_value' => 'Staff Software Engineer',
            ])
            ->assertRedirect();

        $fresh = $listing->fresh();
        $this->assertSame('Staff Software Engineer', $fresh->parsed_role_title);

        $slot = $fresh->parsedField('role_title');
        $this->assertSame('Staff Software Engineer', $slot['value']);
        $this->assertEquals(1.0, $slot['confidence']);
        $this->assertSame('explicit', $slot['source']);
        $this->assertSame('user_correction', $slot['evidence']);

        $correction = ParseCorrection::query()->sole();
        $this->assertSame($listing->id, $correction->listing_id);
        $this->assertSame($this->user->id, $correction->user_id);
        $this->assertSame('role_title', $correction->field);
        $this->assertSame(['value' => 'Software Engineer'], $correction->old_value);
        $this->assertSame(0.6, $correction->old_confidence);
        $this->assertSame('inferred', $correction->old_source);
        $this->assertSame(['value' => 'Staff Software Engineer'], $correction->new_value);
    }

    #[Test]
    public function correcting_remote_type_updates_compound_slot_and_denormalized_column(): void
    {
        $listing = AggregatorListing::factory()->create([
            'source_id' => $this->source->id,
            'parsed_remote_type' => 'on_site',
            'parsed' => [
                'remote' => [
                    'value' => ['type' => 'on_site', 'timezone' => 'UTC'],
                    'confidence' => 0.5,
                    'source' => 'inferred',
                    'evidence' => null,
                ],
            ],
        ]);

        $this->actingAs($this->user)
            ->post("/discover/{$listing->id}/corrections", [
                'field' => 'remote_type',
                'new_value' => 'remote',
            ])
            ->assertRedirect();

        $fresh = $listing->fresh();
        $this->assertSame('remote', $fresh->parsed_remote_type);

        $slot = $fresh->parsedField('remote');
        $this->assertSame('remote', $slot['value']['type']);
        // Preserve sibling sub-keys we didn't touch.
        $this->assertSame('UTC', $slot['value']['timezone']);
    }

    #[Test]
    public function correcting_comp_min_usd_sets_has_comp_and_preserves_max(): void
    {
        $listing = AggregatorListing::factory()->create([
            'source_id' => $this->source->id,
            'has_comp' => false,
            'parsed_comp_min_usd' => null,
            'parsed_comp_max_usd' => null,
            'parsed' => [],
        ]);

        $this->actingAs($this->user)
            ->post("/discover/{$listing->id}/corrections", [
                'field' => 'comp_min_usd',
                'new_value' => 180000,
            ])
            ->assertRedirect();

        $fresh = $listing->fresh();
        $this->assertSame(180000, $fresh->parsed_comp_min_usd);
        $this->assertTrue($fresh->has_comp);

        $slot = $fresh->parsedField('compensation');
        $this->assertSame(180000, $slot['value']['min_usd']);
    }

    #[Test]
    public function correcting_company_name_recomputes_normalized_key_and_role_hash(): void
    {
        // normalized_company_key / role_hash are set once at ingest and
        // otherwise never touched -- a correction that changes company_name
        // must recompute both, or the dedup / auto-dismiss / auto-link /
        // boomerang gates keep matching against the pre-correction company.
        $listing = AggregatorListing::factory()->create([
            'source_id' => $this->source->id,
            'parsed_company_name' => 'Acme Corp',
            'parsed_role_title' => 'Backend Engineer',
            'normalized_company_key' => 'acme',
            'normalized_role_key' => 'backendengineer',
            'location_bucket' => 'worldwide',
            'role_hash' => 'stale-hash',
            'parsed' => [
                'company_name' => [
                    'value' => 'Acme Corp',
                    'confidence' => 0.7,
                    'source' => 'inferred',
                    'evidence' => null,
                ],
            ],
        ]);

        $this->actingAs($this->user)
            ->post("/discover/{$listing->id}/corrections", [
                'field' => 'company_name',
                'new_value' => 'Globex Corporation',
            ])
            ->assertRedirect();

        $fresh = $listing->fresh();
        $normalizer = $this->app->make(CompanyKeyNormalizer::class);
        $hasher = $this->app->make(RoleHasher::class);
        $expectedCompanyKey = $normalizer->normalize('Globex Corporation');

        $this->assertNotSame('acme', $fresh->normalized_company_key);
        $this->assertSame($expectedCompanyKey, $fresh->normalized_company_key);
        $this->assertNotSame('stale-hash', $fresh->role_hash);
        $this->assertSame(
            $hasher->hash($expectedCompanyKey, $fresh->normalized_role_key, $fresh->location_bucket),
            $fresh->role_hash,
        );
    }

    #[Test]
    public function correcting_stack_tags_replaces_array_and_dedups_blanks(): void
    {
        $listing = AggregatorListing::factory()->create([
            'source_id' => $this->source->id,
            'stack_tags' => ['python'],
            'parsed' => [],
        ]);

        $response = $this->actingAs($this->user)
            ->from('/discover')
            ->post("/discover/{$listing->id}/corrections", [
                'field' => 'stack_tags',
                'new_value' => ['typescript', 'react', ' ', ''],
            ]);
        $response->assertSessionHasNoErrors();
        $response->assertRedirect();

        $fresh = $listing->fresh();
        $this->assertSame(['typescript', 'react'], $fresh->stack_tags);
    }

    #[Test]
    public function correcting_any_field_backfills_discipline_from_the_existing_parsed_slot(): void
    {
        // `discipline` isn't itself a correctable field, but DisciplineExtractor
        // already wrote it into the `parsed` jsonb blob before the denormalized
        // column existed. Any correction should backfill the column for free.
        $listing = AggregatorListing::factory()->create([
            'source_id' => $this->source->id,
            'discipline' => null,
            'parsed_seniority' => 'mid',
            'parsed' => [
                'discipline' => [
                    'value' => 'frontend',
                    'confidence' => 0.8,
                    'source' => 'inferred',
                    'evidence' => 'matched: frontend',
                ],
                'seniority' => [
                    'value' => 'mid',
                    'confidence' => 0.8,
                    'source' => 'inferred',
                    'evidence' => null,
                ],
            ],
        ]);

        $this->actingAs($this->user)
            ->post("/discover/{$listing->id}/corrections", [
                'field' => 'seniority',
                'new_value' => 'senior',
            ])
            ->assertRedirect();

        $this->assertSame('frontend', $listing->fresh()->discipline);
    }

    #[Test]
    public function saving_an_unchanged_value_is_a_no_op(): void
    {
        $listing = AggregatorListing::factory()->create([
            'source_id' => $this->source->id,
            'parsed_role_title' => 'Backend Engineer',
            'parsed' => [
                'role_title' => [
                    'value' => 'Backend Engineer',
                    'confidence' => 0.9,
                    'source' => 'explicit',
                    'evidence' => null,
                ],
            ],
        ]);

        $this->actingAs($this->user)
            ->post("/discover/{$listing->id}/corrections", [
                'field' => 'role_title',
                'new_value' => 'Backend Engineer',
            ])
            ->assertRedirect();

        $this->assertSame(0, ParseCorrection::query()->count());
        $slot = $listing->fresh()->parsedField('role_title');
        // The slot should remain untouched (confidence stays at 0.9, not bumped to 1.0).
        $this->assertSame(0.9, $slot['confidence']);
        $this->assertSame('explicit', $slot['source']);
    }

    #[Test]
    public function rejects_unknown_field(): void
    {
        $listing = AggregatorListing::factory()->create([
            'source_id' => $this->source->id,
        ]);

        $this->actingAs($this->user)
            ->from('/discover')
            ->post("/discover/{$listing->id}/corrections", [
                'field' => 'salary_currency_in_yuan',
                'new_value' => 'CNY',
            ])
            ->assertSessionHasErrors('field');

        $this->assertSame(0, ParseCorrection::query()->count());
    }

    #[Test]
    public function rejects_company_name_when_blank(): void
    {
        $listing = AggregatorListing::factory()->create([
            'source_id' => $this->source->id,
        ]);

        $this->actingAs($this->user)
            ->from('/discover')
            ->post("/discover/{$listing->id}/corrections", [
                'field' => 'company_name',
                'new_value' => '',
            ])
            ->assertSessionHasErrors('new_value');
    }

    #[Test]
    public function cannot_correct_listings_from_other_tenants(): void
    {
        $listing = AggregatorListing::factory()->create([
            'source_id' => $this->source->id,
        ]);

        $otherTenant = Tenant::factory()->create();
        app(TenantContext::class)->setCurrent($otherTenant);
        $otherUser = User::factory()->create();

        $this->actingAs($otherUser)
            ->post("/discover/{$listing->id}/corrections", [
                'field' => 'role_title',
                'new_value' => 'Other Tenant Edit',
            ])
            ->assertNotFound();
    }

    #[Test]
    public function correction_endpoint_requires_authentication(): void
    {
        $listing = AggregatorListing::factory()->create([
            'source_id' => $this->source->id,
        ]);

        $this->post("/discover/{$listing->id}/corrections", [
            'field' => 'role_title',
            'new_value' => 'Anything',
        ])->assertRedirect('/login');
    }
}
