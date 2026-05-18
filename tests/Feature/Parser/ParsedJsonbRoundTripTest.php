<?php

declare(strict_types=1);

namespace Tests\Feature\Parser;

use App\Models\AggregatorListing;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Asserts that the per-field {value, confidence, source, evidence} shape
 * written by the persister via ParsedListing::toArray() round-trips through
 * the `parsed` jsonb column without losing structure. This is the substrate
 * the inline-correction UI reads, so any drift here breaks Step 4.
 */
final class ParsedJsonbRoundTripTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::factory()->create(['slug' => 'parsed-roundtrip']);
        app(TenantContext::class)->setCurrent($tenant);
    }

    #[Test]
    public function parsed_jsonb_round_trips_per_field_shape(): void
    {
        $parsed = [
            'company_name' => [
                'value' => 'Stripe',
                'confidence' => 0.95,
                'source' => 'explicit',
                'evidence' => 'structured.company_name',
            ],
            'role_title' => [
                'value' => 'Staff Software Engineer',
                'confidence' => 0.9,
                'source' => 'explicit',
                'evidence' => 'structured.title',
            ],
            'compensation' => [
                'value' => [
                    'min' => 220000,
                    'max' => 280000,
                    'currency' => 'USD',
                    'interval' => 'annual',
                    'equity' => true,
                    'min_usd' => 220000,
                    'max_usd' => 280000,
                ],
                'confidence' => 0.85,
                'source' => 'explicit',
                'evidence' => 'structured.compensation',
            ],
            'seniority' => [
                'value' => null,
                'confidence' => 0.0,
                'source' => 'missing',
                'evidence' => null,
            ],
            'stack_tags' => [
                'value' => ['go', 'kubernetes', 'postgresql'],
                'confidence' => 0.6,
                'source' => 'inferred',
                'evidence' => 'structured:0 body:3',
            ],
        ];

        $listing = AggregatorListing::factory()->create(['parsed' => $parsed]);

        $reloaded = $listing->fresh()->parsed;

        // jsonb does not preserve key ordering and decodes 0.0 as int 0; assertEquals
        // gives us field-level equivalence without those false-negative pitfalls.
        $this->assertEqualsCanonicalizing(array_keys($parsed), array_keys($reloaded));
        foreach ($parsed as $field => $shape) {
            $this->assertEquals($shape['value'], $reloaded[$field]['value'], "value mismatch for {$field}");
            $this->assertEquals($shape['source'], $reloaded[$field]['source'], "source mismatch for {$field}");
            $this->assertEqualsWithDelta(
                $shape['confidence'],
                (float) $reloaded[$field]['confidence'],
                0.0001,
                "confidence mismatch for {$field}",
            );
            $this->assertSame($shape['evidence'], $reloaded[$field]['evidence']);
        }

        $this->assertSame('Staff Software Engineer', $reloaded['role_title']['value']);
        $this->assertSame(220000, $reloaded['compensation']['value']['min_usd']);
        $this->assertSame('missing', $reloaded['seniority']['source']);
        $this->assertSame(['go', 'kubernetes', 'postgresql'], $reloaded['stack_tags']['value']);
    }

    #[Test]
    public function field_at_helper_returns_per_field_slot(): void
    {
        $listing = AggregatorListing::factory()->create([
            'parsed' => [
                'company_name' => [
                    'value' => 'Acme',
                    'confidence' => 0.7,
                    'source' => 'inferred',
                    'evidence' => 'body',
                ],
            ],
        ]);

        $field = $listing->parsedField('company_name');

        $this->assertNotNull($field);
        $this->assertSame('Acme', $field['value']);
        $this->assertEqualsWithDelta(0.7, $field['confidence'], 0.0001);
        $this->assertSame('inferred', $field['source']);

        $this->assertNull($listing->parsedField('nonexistent'));
    }
}
