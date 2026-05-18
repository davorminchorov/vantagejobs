<?php

declare(strict_types=1);

namespace Tests\Unit\Signals;

use App\Scrapers\Aggregator\CompanyKeyNormalizer;
use App\Signals\Layoffs\TrueUpHtmlParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TrueUpHtmlParserTest extends TestCase
{
    private TrueUpHtmlParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new TrueUpHtmlParser(new CompanyKeyNormalizer());
    }

    #[Test]
    public function it_extracts_layoff_records_from_next_data_blob(): void
    {
        $payload = [
            'props' => [
                'pageProps' => [
                    'layoffs' => [
                        [
                            'company' => 'Acme, Inc.',
                            'date' => '2026-04-12',
                            'laidOff' => 120,
                            'percent' => 0.10,
                            'industry' => 'Retail',
                            'country' => 'US',
                            'stage' => 'Series B',
                        ],
                        [
                            'company' => 'Globex',
                            'date' => '2026-04-10',
                            'headcount' => 500,
                            'percent' => 25,
                            'industry' => 'Finance',
                            'country' => 'US',
                            'stage' => 'Series D',
                        ],
                    ],
                ],
            ],
        ];

        $html = '<html><body><script id="__NEXT_DATA__" type="application/json">'
            .json_encode($payload, JSON_THROW_ON_ERROR)
            .'</script></body></html>';

        $records = $this->parser->parse($html);

        $this->assertCount(2, $records);
        $this->assertSame('Acme, Inc.', $records[0]->companyName);
        $this->assertSame('acme', $records[0]->normalizedKey);
        $this->assertSame(120, $records[0]->headcount);
        $this->assertSame(10.0, $records[0]->percent);
        $this->assertSame('trueup', $records[0]->source);

        $this->assertSame('globex', $records[1]->normalizedKey);
        $this->assertSame(500, $records[1]->headcount);
        $this->assertSame(25.0, $records[1]->percent);
    }

    #[Test]
    public function it_returns_empty_when_next_data_is_missing(): void
    {
        $records = $this->parser->parse('<html><body><p>nope</p></body></html>');

        $this->assertSame([], $records);
    }

    #[Test]
    public function it_skips_rows_with_unparseable_dates(): void
    {
        $payload = [
            'data' => [
                'rows' => [
                    [
                        'company' => 'Acme',
                        'date' => 'not-a-date',
                        'laidOff' => 10,
                    ],
                    [
                        'company' => 'Globex',
                        'date' => '2026-05-01',
                        'laidOff' => 20,
                    ],
                ],
            ],
        ];

        $html = '<script id="__NEXT_DATA__">'.json_encode($payload, JSON_THROW_ON_ERROR).'</script>';

        $records = $this->parser->parse($html);

        $this->assertCount(1, $records);
        $this->assertSame('globex', $records[0]->normalizedKey);
    }
}
