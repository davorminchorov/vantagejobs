<?php

declare(strict_types=1);

namespace Tests\Feature\Roles;

use App\Models\AggregatorListing;
use App\Models\AggregatorSource;
use App\Models\Company;
use App\Models\Role;
use App\Enums\AggregatorSourceType;
use App\Roles\RoleDescriptionExtractor;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class RoleDescriptionExtractorTest extends TestCase
{
    use RefreshDatabase;

    private RoleDescriptionExtractor $extractor;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->setCurrent(Tenant::factory()->create());
        $this->extractor = app(RoleDescriptionExtractor::class);
        $this->company = Company::factory()->create();
    }

    /**
     * @param  array<string, mixed>|null  $raw
     */
    private function role(string $source, ?array $raw, array $extra = []): Role
    {
        return Role::factory()->create([
            'company_id' => $this->company->id,
            'source' => $source,
            'raw' => $raw,
            ...$extra,
        ]);
    }

    #[Test]
    public function greenhouse_entity_encoded_html_becomes_plain_text(): void
    {
        $role = $this->role('greenhouse', [
            'content' => '&lt;h2&gt;&lt;strong&gt;Who we are &lt;/strong&gt;&lt;/h2&gt;'."\n"
                .'&lt;p&gt;We&amp;#39;re building &amp;amp; shipping.&amp;nbsp;Join us.&lt;/p&gt;'
                .'&lt;ul&gt;&lt;li&gt;Python&lt;/li&gt;&lt;li&gt;Postgres&lt;/li&gt;&lt;/ul&gt;',
        ]);

        $text = $this->extractor->for($role);

        $this->assertSame("Who we are\n\nWe're building & shipping. Join us.\n\n- Python\n- Postgres", $text);
    }

    #[Test]
    public function lever_combines_description_lists_and_additional(): void
    {
        $role = $this->role('lever', [
            'descriptionPlain' => "About airSlate\nWe build workflow tools.",
            'lists' => [
                ['text' => 'Responsibilities:', 'content' => '<li>Forecast &amp; plan</li><li>Report</li>'],
            ],
            'additionalPlain' => 'We are an equal opportunity employer.',
        ]);

        $this->assertSame(
            "About airSlate\nWe build workflow tools.\n\nResponsibilities:\n\n- Forecast & plan\n- Report\n\nWe are an equal opportunity employer.",
            $this->extractor->for($role),
        );
    }

    #[Test]
    public function teamtailor_uses_description_html(): void
    {
        $role = $this->role('teamtailor', ['description' => '<p>Millions of people.</p><p><strong>What you do</strong><br>Sell.</p>']);

        $this->assertSame("Millions of people.\n\nWhat you do\nSell.", $this->extractor->for($role));
    }

    #[Test]
    public function ashby_uses_description_html(): void
    {
        $role = $this->role('ashby', ['descriptionHtml' => '<h1>About</h1><p style="min-height:1.5em">We make games.</p>']);

        $this->assertTrue($this->extractor->hasDescription($role));
        $this->assertSame("About\n\nWe make games.", $this->extractor->for($role));
    }

    #[Test]
    public function ashby_falls_back_to_description_plain(): void
    {
        $role = $this->role('ashby', ['descriptionPlain' => "ABOUT\n\nWe make games."]);

        $this->assertTrue($this->extractor->hasDescription($role));
        $this->assertSame("ABOUT\n\nWe make games.", $this->extractor->for($role));
    }

    #[Test]
    public function workable_uses_description_html(): void
    {
        $role = $this->role('workable', ['description' => '<p>We are&nbsp;seeking a <strong>hunter</strong>.</p>']);

        $this->assertTrue($this->extractor->hasDescription($role));
        $this->assertSame('We are seeking a hunter.', $this->extractor->for($role));
    }

    #[Test]
    public function workable_without_description_has_none(): void
    {
        $role = $this->role('workable', ['title' => 'Driver']);

        $this->assertFalse($this->extractor->hasDescription($role));
        $this->assertNull($this->extractor->for($role));
    }

    #[Test]
    public function recruitee_joins_description_and_requirements(): void
    {
        $role = $this->role('recruitee', [
            'description' => '<p>Do great work.</p>',
            'requirements' => '<p>&nbsp;Develop frontends</p><ul><li><p>Liquid</p></li></ul>',
        ]);

        $this->assertSame("Do great work.\n\nDevelop frontends\n\n- Liquid", $this->extractor->for($role));
    }

    #[Test]
    public function personio_renders_named_sections(): void
    {
        $role = $this->role('personio', [
            'jobDescriptions' => ['jobDescription' => [
                ['name' => 'What we look for:', 'value' => ['<p>Curiosity</p>']],
                ['name' => 'What you can expect:', 'value' => '<ul><li>Remote work</li></ul>'],
                ['name' => 'Empty section', 'value' => []],
            ]],
        ]);

        $this->assertSame("What we look for:\n\nCuriosity\n\nWhat you can expect:\n\n- Remote work", $this->extractor->for($role));
        $this->assertTrue($this->extractor->hasDescription($role));
    }

    #[Test]
    public function personio_with_only_empty_sections_is_null(): void
    {
        $role = $this->role('personio', [
            'jobDescriptions' => ['jobDescription' => [['name' => 'What we require:', 'value' => []]]],
        ]);
        $listShape = $this->role('personio', ['jobDescriptions' => []]);

        $this->assertNull($this->extractor->for($role));
        $this->assertFalse($this->extractor->hasDescription($role));
        $this->assertNull($this->extractor->for($listShape));
    }

    #[Test]
    public function pinpoint_includes_section_headers(): void
    {
        $role = $this->role('pinpoint', [
            'description' => '<div><!--block-->Reward Gateway is a platform.</div>',
            'key_responsibilities_header' => 'Some of Your Responsibilities:',
            'key_responsibilities' => '<ul><li><!--block-->Own accounts&nbsp;</li></ul>',
            'skills_knowledge_expertise_header' => 'Experience and Skills You Need:',
            'skills_knowledge_expertise' => '<ul><li><!--block-->Client success</li></ul>',
            'benefits_header' => 'The Interview Process:',
            'benefits' => '<ul><li><!--block-->Video screen</li></ul>',
        ]);

        $this->assertSame(
            "Reward Gateway is a platform.\n\nSome of Your Responsibilities:\n\n- Own accounts\n\nExperience and Skills You Need:\n\n- Client success\n\nThe Interview Process:\n\n- Video screen",
            $this->extractor->for($role),
        );
    }

    #[Test]
    public function aggregator_role_uses_linked_listing_body(): void
    {
        $source = AggregatorSource::factory()->ofType(AggregatorSourceType::ARBEITNOW)->create();
        $listing = AggregatorListing::factory()->create([
            'source_id' => $source->id,
            'raw' => ['title' => 'Dev', 'company_name' => 'Acme', 'description' => '<p>Write &amp;amp; ship code.</p>'],
        ]);
        $role = $this->role('aggregator:arbeitnow', null, ['aggregator_listing_id' => $listing->id]);

        $this->assertSame('Write & ship code.', $this->extractor->for($role));
        $this->assertTrue($this->extractor->hasDescription($role));
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('sourcesWithoutDescriptions')]
    public function sources_without_stored_text_return_null(string $source): void
    {
        $role = $this->role($source, ['title' => 'Engineer', 'description' => 'ignored']);

        $this->assertNull($this->extractor->for($role));
        $this->assertFalse($this->extractor->hasDescription($role));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function sourcesWithoutDescriptions(): array
    {
        return array_combine(
            ['workday', 'smartrecruiters', 'rippling', 'bamboohr'],
            array_map(static fn (string $s): array => [$s], ['workday', 'smartrecruiters', 'rippling', 'bamboohr']),
        );
    }

    #[Test]
    public function empty_content_is_null_and_null_raw_is_safe(): void
    {
        $this->assertNull($this->extractor->for($this->role('greenhouse', ['content' => '  '])));
        $this->assertNull($this->extractor->for($this->role('lever', null)));
    }

    #[Test]
    public function long_text_is_capped_with_an_ellipsis(): void
    {
        $role = $this->role('teamtailor', ['description' => '<p>'.str_repeat('a', 12_000).'</p>']);

        $text = $this->extractor->for($role);

        $this->assertSame(RoleDescriptionExtractor::LIMIT + 1, mb_strlen($text));
        $this->assertStringEndsWith('…', $text);
    }
}
