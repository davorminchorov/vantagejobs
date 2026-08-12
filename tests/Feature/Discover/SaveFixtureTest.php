<?php

declare(strict_types=1);

namespace Tests\Feature\Discover;

use App\Enums\AggregatorSourceType;
use App\Models\AggregatorListing;
use App\Models\AggregatorSource;
use App\Models\User;
use App\Scrapers\Aggregator\SaveFixtureAction;
use App\Scrapers\Aggregator\SourceContextFactory;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SaveFixtureTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    private string $fixtureDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->setCurrent($this->tenant);
        $this->user = User::factory()->create();

        // Redirect SaveFixtureAction writes into a per-test tempdir so the
        // real fixture directory is left alone.
        $this->fixtureDir = sys_get_temp_dir().'/vantagejobs-fixtures-'.bin2hex(random_bytes(4));
        mkdir($this->fixtureDir, 0755, true);

        $this->app->bind(SaveFixtureAction::class, fn ($app): SaveFixtureAction => new SaveFixtureAction(
            $app->make(SourceContextFactory::class),
            $this->fixtureDir,
        ));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->fixtureDir)) {
            $this->rrm($this->fixtureDir);
        }
        parent::tearDown();
    }

    private function rrm(string $path): void
    {
        if (! is_dir($path)) {
            @unlink($path);

            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->rrm($path.'/'.$entry);
        }
        @rmdir($path);
    }

    #[Test]
    public function writes_fixture_under_source_directory_with_short_external_id(): void
    {
        $source = AggregatorSource::factory()->ofType(AggregatorSourceType::REMOTIVE)->create();
        $listing = AggregatorListing::factory()->create([
            'source_id' => $source->id,
            'external_id' => '12345',
            'raw' => ['title' => 'Staff Engineer', 'company_name' => 'Stripe', 'description' => '<p>Body</p>'],
            'parsed_company_name' => 'Stripe',
            'parsed_role_title' => 'Staff Engineer',
            'parsed_seniority' => 'staff',
            'parsed_remote_type' => 'remote',
            'parsed_comp_min_usd' => 200000,
            'parsed_comp_max_usd' => 280000,
            'has_comp' => true,
            'stack_tags' => ['typescript', 'react'],
        ]);

        $response = $this->actingAs($this->user)->post("/discover/{$listing->id}/save-fixture");
        $response->assertRedirect();
        $response->assertSessionHas('flash');

        $path = $this->fixtureDir.'/remotive/remotive-12345.json';
        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertSame('remotive', $decoded['source']);
        $this->assertSame('12345', $decoded['external_id']);
        $this->assertIsArray($decoded['raw']);
        $this->assertSame('Stripe', $decoded['raw']['company_name']);
        $this->assertIsArray($decoded['structured']);
        $this->assertSame('Stripe', $decoded['expected']['company_name']);
        $this->assertSame('Staff Engineer', $decoded['expected']['role_title']);
        $this->assertSame('staff', $decoded['expected']['seniority']);
        $this->assertSame('remote', $decoded['expected']['remote.type']);
        $this->assertSame(200000, $decoded['expected']['compensation.min_usd']);
        $this->assertSame(280000, $decoded['expected']['compensation.max_usd']);
        $this->assertSame(['typescript', 'react'], $decoded['expected']['stack_tags_include']);
    }

    #[Test]
    public function hashes_long_or_non_alphanumeric_external_ids(): void
    {
        $source = AggregatorSource::factory()->ofType(AggregatorSourceType::WEWORKREMOTELY)->create();
        $externalId = 'https://weworkremotely.com/remote-jobs/acme-staff-engineer-position-7891';
        $listing = AggregatorListing::factory()->create([
            'source_id' => $source->id,
            'external_id' => $externalId,
            'raw' => [
                'guid' => $externalId,
                'title' => 'Acme: Staff Engineer',
                'description' => '<p>Body</p>',
                'region' => 'Worldwide',
            ],
            'parsed_company_name' => 'Acme',
            'parsed_role_title' => 'Staff Engineer',
        ]);

        $this->actingAs($this->user)->post("/discover/{$listing->id}/save-fixture")->assertRedirect();

        $hash = substr(md5($externalId), 0, 12);
        $path = $this->fixtureDir.'/weworkremotely/weworkremotely-'.$hash.'.json';
        $this->assertFileExists($path);
    }

    #[Test]
    public function overwrites_existing_fixture_on_repeated_save(): void
    {
        $source = AggregatorSource::factory()->ofType(AggregatorSourceType::REMOTIVE)->create();
        $listing = AggregatorListing::factory()->create([
            'source_id' => $source->id,
            'external_id' => 'abc1',
            'raw' => ['title' => 'Engineer'],
            'parsed_role_title' => 'Engineer',
        ]);

        $this->actingAs($this->user)->post("/discover/{$listing->id}/save-fixture")->assertRedirect();

        $path = $this->fixtureDir.'/remotive/remotive-abc1.json';
        $first = (string) file_get_contents($path);

        // Mutate parsed values and re-save -- the file content should change.
        $listing->parsed_role_title = 'Staff Engineer';
        $listing->save();

        $this->actingAs($this->user)->post("/discover/{$listing->id}/save-fixture")->assertRedirect();
        $second = (string) file_get_contents($path);

        $this->assertNotSame($first, $second);
        $decoded = json_decode($second, true);
        $this->assertSame('Staff Engineer', $decoded['expected']['role_title']);
    }

    #[Test]
    public function expected_block_omits_unparsed_fields(): void
    {
        $source = AggregatorSource::factory()->ofType(AggregatorSourceType::REMOTIVE)->create();
        $listing = AggregatorListing::factory()->create([
            'source_id' => $source->id,
            'external_id' => 'xyz9',
            'raw' => ['title' => 'Unknown'],
            'parsed_company_name' => null,
            'parsed_role_title' => 'Engineer',
            'parsed_seniority' => null,
            'parsed_remote_type' => null,
            'parsed_comp_min_usd' => null,
            'parsed_comp_max_usd' => null,
            'has_comp' => false,
            'stack_tags' => null,
            'location_bucket' => null,
        ]);

        $this->actingAs($this->user)->post("/discover/{$listing->id}/save-fixture")->assertRedirect();

        $path = $this->fixtureDir.'/remotive/remotive-xyz9.json';
        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertSame(['role_title' => 'Engineer'], $decoded['expected']);
    }

    #[Test]
    public function save_fixture_requires_authentication(): void
    {
        $source = AggregatorSource::factory()->ofType(AggregatorSourceType::REMOTIVE)->create();
        $listing = AggregatorListing::factory()->create(['source_id' => $source->id]);

        $this->post("/discover/{$listing->id}/save-fixture")->assertRedirect('/login');
    }

    #[Test]
    public function save_fixture_is_not_found_outside_local_and_testing_environments(): void
    {
        $source = AggregatorSource::factory()->ofType(AggregatorSourceType::REMOTIVE)->create();
        $listing = AggregatorListing::factory()->create(['source_id' => $source->id]);

        $this->app['env'] = 'production';

        $this->actingAs($this->user)->post("/discover/{$listing->id}/save-fixture")->assertNotFound();
    }
}
