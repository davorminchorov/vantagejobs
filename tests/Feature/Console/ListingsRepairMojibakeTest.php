<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\AggregatorSourceType;
use App\Models\AggregatorListing;
use App\Models\AggregatorSource;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ListingsRepairMojibakeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create(['slug' => 'primary']);
        app(TenantContext::class)->setCurrent($this->tenant);
    }

    #[Test]
    public function repairs_and_reparses_a_double_encoded_remoteok_listing(): void
    {
        $source = AggregatorSource::factory()->ofType(AggregatorSourceType::REMOTEOK)->create();

        $listing = AggregatorListing::factory()->create([
            'source_id' => $source->id,
            'raw' => [
                'id' => 'rok-1',
                'company' => 'Universia MÃ©xico',
                'position' => 'Auxiliar de OperaciÃ³n',
                'description' => '<p>RÃ³l de soporte remoto.</p>',
                'location' => 'CoyoacÃ¡n, Ciudad de MÃ©xico',
            ],
            'parsed_company_name' => 'Universia MÃ©xico',
            'parsed_role_title' => 'Auxiliar de OperaciÃ³n',
            'parser_version' => 1,
        ]);

        Artisan::call('listings:repair-mojibake');
        $decoded = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $decoded['checked']);
        $this->assertSame(1, $decoded['repaired']);

        $listing->refresh();
        $this->assertSame('Universia México', $listing->raw['company']);
        $this->assertSame('Auxiliar de Operación', $listing->raw['position']);
        $this->assertSame('Universia México', $listing->parsed_company_name);
        $this->assertSame('Auxiliar de Operación', $listing->parsed_role_title);
        // ReparseAction::reparse() doesn't touch parser_version — only
        // parser:reparse-all pins and bumps that against its own target.
        $this->assertSame(1, $listing->parser_version);
    }

    #[Test]
    public function leaves_a_clean_listing_untouched(): void
    {
        $source = AggregatorSource::factory()->ofType(AggregatorSourceType::REMOTEOK)->create();

        $listing = AggregatorListing::factory()->create([
            'source_id' => $source->id,
            'raw' => ['id' => 'rok-2', 'company' => 'Acme Corp', 'position' => 'Backend Engineer'],
            'parsed_company_name' => 'Acme Corp',
            'parser_version' => 1,
        ]);

        Artisan::call('listings:repair-mojibake');
        $decoded = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $decoded['checked']);
        $this->assertSame(0, $decoded['repaired']);

        $this->assertSame(1, $listing->refresh()->parser_version);
    }

    #[Test]
    public function skips_listings_from_sources_other_than_remoteok_and_himalayas(): void
    {
        $source = AggregatorSource::factory()->ofType(AggregatorSourceType::HACKERNEWS)->create();

        AggregatorListing::factory()->create([
            'source_id' => $source->id,
            'raw' => ['id' => 'hn-1', 'company' => 'MÃ©xico Co'],
        ]);

        Artisan::call('listings:repair-mojibake');
        $decoded = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $decoded['checked']);
        $this->assertSame(0, $decoded['repaired']);
    }

    #[Test]
    public function running_it_twice_is_idempotent(): void
    {
        $source = AggregatorSource::factory()->ofType(AggregatorSourceType::HIMALAYAS)->create();

        AggregatorListing::factory()->create([
            'source_id' => $source->id,
            'raw' => ['guid' => 'him-1', 'companyName' => 'GlÃ¼ckliche GÃ¤ste GmbH'],
        ]);

        Artisan::call('listings:repair-mojibake');
        $first = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(1, $first['repaired']);

        Artisan::call('listings:repair-mojibake');
        $second = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(0, $second['repaired']);
    }

    #[Test]
    public function only_sweeps_the_given_tenant_when_the_option_is_passed(): void
    {
        $source = AggregatorSource::factory()->ofType(AggregatorSourceType::REMOTEOK)->create();
        AggregatorListing::factory()->create([
            'source_id' => $source->id,
            'raw' => ['id' => 'rok-3', 'company' => 'MÃ©xico Co'],
        ]);

        $otherTenant = Tenant::factory()->create(['slug' => 'other']);
        app(TenantContext::class)->setCurrent($otherTenant);
        $otherSource = AggregatorSource::factory()->ofType(AggregatorSourceType::REMOTEOK)->create();
        AggregatorListing::factory()->create([
            'source_id' => $otherSource->id,
            'raw' => ['id' => 'rok-4', 'company' => 'MÃ©xico Co'],
        ]);
        app(TenantContext::class)->setCurrent($this->tenant);

        Artisan::call('listings:repair-mojibake', ['--tenant' => 'primary']);
        $decoded = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $decoded['tenants_swept']);
        $this->assertSame(1, $decoded['checked']);
        $this->assertSame(1, $decoded['repaired']);
    }
}
