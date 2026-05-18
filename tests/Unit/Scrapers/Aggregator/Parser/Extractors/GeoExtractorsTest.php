<?php

declare(strict_types=1);

namespace Tests\Unit\Scrapers\Aggregator\Parser\Extractors;

use App\Enums\AggregatorSourceType;
use App\Scrapers\Aggregator\Parser\ExtractionSource;
use App\Scrapers\Aggregator\Parser\Extractors\LocationExtractor;
use App\Scrapers\Aggregator\Parser\Extractors\RegionExtractor;
use App\Scrapers\Aggregator\Parser\Extractors\RemoteExtractor;
use App\Scrapers\Aggregator\Parser\SourceContext;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GeoExtractorsTest extends TestCase
{
    /* ----- Location ----- */

    #[Test]
    public function location_from_himalayas_array(): void
    {
        $field = (new LocationExtractor())->extract($this->ctx(['locationRestrictions' => ['Worldwide', 'EMEA']]));
        $this->assertSame('Worldwide, EMEA', $field->value);
        $this->assertSame(ExtractionSource::EXPLICIT, $field->source);
    }

    #[Test]
    public function location_from_remotive_field(): void
    {
        $this->assertSame('USA Only', (new LocationExtractor())->extract($this->ctx(['candidate_required_location' => 'USA Only']))->value);
    }

    #[Test]
    public function location_from_remoteok_field(): void
    {
        $this->assertSame('Berlin, Germany', (new LocationExtractor())->extract($this->ctx(['location' => 'Berlin, Germany']))->value);
    }

    #[Test]
    public function location_worldwide_gets_lower_confidence(): void
    {
        $field = (new LocationExtractor())->extract($this->ctx(['location' => 'Worldwide']));

        $this->assertSame(0.6, $field->confidence);
    }

    #[Test]
    public function location_missing_when_blank(): void
    {
        $this->assertTrue((new LocationExtractor())->extract($this->ctx(['location' => '   ']))->isMissing());
    }

    /* ----- Region ----- */

    #[Test]
    public function region_worldwide(): void
    {
        $this->assertSame('worldwide', (new RegionExtractor())->extract($this->ctx(['location' => 'Anywhere in the world']))->value);
    }

    #[Test]
    public function region_emea(): void
    {
        $this->assertSame('emea', (new RegionExtractor())->extract($this->ctx(['candidate_required_location' => 'EMEA']))->value);
    }

    #[Test]
    public function region_us_from_united_states(): void
    {
        $this->assertSame('us', (new RegionExtractor())->extract($this->ctx(['location' => 'United States']))->value);
    }

    #[Test]
    public function region_worldwide_beats_us_in_compound_string(): void
    {
        $this->assertSame('worldwide', (new RegionExtractor())->extract($this->ctx(['location' => 'Worldwide (US time zone preferred)']))->value);
    }

    #[Test]
    public function region_missing_on_unknown(): void
    {
        $this->assertTrue((new RegionExtractor())->extract($this->ctx(['location' => 'Mars Colony']))->isMissing());
    }

    /* ----- Remote ----- */

    #[Test]
    public function remote_type_detected_from_title_keyword(): void
    {
        $field = (new RemoteExtractor())->extract($this->ctx(['title' => 'Senior Backend Engineer (Remote)']));

        $this->assertSame('remote', $field->value['type']);
        $this->assertSame(ExtractionSource::INFERRED, $field->source);
    }

    #[Test]
    public function remote_type_hybrid_beats_remote_when_both_appear(): void
    {
        $field = (new RemoteExtractor())->extract($this->ctx(['title' => 'Hybrid role with some remote days']));

        $this->assertSame('hybrid', $field->value['type']);
    }

    #[Test]
    public function remote_type_on_site_from_keyword(): void
    {
        $field = (new RemoteExtractor())->extract($this->ctx(['title' => 'On-site Software Engineer, NYC']));

        $this->assertSame('on_site', $field->value['type']);
    }

    #[Test]
    public function remote_with_himalayas_timezone_data_is_explicit(): void
    {
        $field = (new RemoteExtractor())->extract($this->ctx([
            'title' => 'Senior Engineer',
            'timezoneRestrictions' => ['UTC+01:00', 'UTC+02:00'],
            'locationRestrictions' => ['EMEA'],
        ]));

        $this->assertSame(ExtractionSource::EXPLICIT, $field->source);
        $this->assertSame(0.9, $field->confidence);
        $this->assertSame(['UTC+01:00', 'UTC+02:00'], $field->value['timezone_restrictions']);
        $this->assertSame(['EMEA'], $field->value['geo_restrictions']);
    }

    #[Test]
    public function remote_geo_normalizes_us_only(): void
    {
        $field = (new RemoteExtractor())->extract($this->ctx(['candidate_required_location' => 'USA Only']));

        $this->assertSame(['US'], $field->value['geo_restrictions']);
    }

    #[Test]
    public function remote_geo_drops_worldwide(): void
    {
        $field = (new RemoteExtractor())->extract($this->ctx(['location' => 'Worldwide']));

        $this->assertSame([], $field->value['geo_restrictions']);
    }

    #[Test]
    public function remote_missing_when_nothing_detected(): void
    {
        $this->assertTrue((new RemoteExtractor())->extract($this->ctx(['title' => 'Engineer']))->isMissing());
    }

    /**
     * @param  array<string, mixed>  $structured
     */
    private function ctx(array $structured): SourceContext
    {
        return new SourceContext(
            sourceType: AggregatorSourceType::HIMALAYAS,
            structured: $structured,
        );
    }
}
