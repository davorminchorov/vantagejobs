<?php

declare(strict_types=1);

namespace Tests\Feature\Signals;

use App\Models\Company;
use App\Models\FundingCandidate;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class FundingNewsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-11-07 12:00:00');
        $this->tenant = Tenant::factory()->create(['slug' => 'primary']);
        app(TenantContext::class)->setCurrent($this->tenant);
    }

    #[Test]
    public function plausible_funding_hits_are_inserted_as_new_candidates(): void
    {
        $company = Company::factory()->create([
            'name' => 'Acme Co',
            'is_active' => true,
        ]);

        Http::fake([
            'news.google.com/*' => Http::response($this->rssFeed([
                [
                    'guid' => 'g1',
                    'title' => 'Acme Co raised $20M Series B',
                    'link' => 'https://news.example.com/g1',
                    'pubDate' => 'Mon, 18 May 2026 10:00:00 +0000',
                ],
                [
                    'guid' => 'g2',
                    'title' => 'Acme Co closes round of funding',
                    'link' => 'https://news.example.com/g2',
                    'pubDate' => 'Tue, 19 May 2026 10:00:00 +0000',
                ],
            ]), 200, ['Content-Type' => 'application/rss+xml']),
        ]);

        Artisan::call('signals:funding:news', ['--tenant' => 'primary']);

        $rows = FundingCandidate::query()
            ->where('company_id', $company->id)
            ->orderBy('external_id')
            ->get();

        $this->assertCount(2, $rows);
        $this->assertSame('new', $rows[0]->status);
        $this->assertContains('Series B', $rows[0]->matched_keywords);
        $this->assertContains('raised', $rows[0]->matched_keywords);
        $this->assertContains('closes round', $rows[1]->matched_keywords);
    }

    #[Test]
    public function off_topic_items_without_preset_keywords_are_dropped(): void
    {
        $company = Company::factory()->create([
            'name' => 'Acme Co',
            'is_active' => true,
        ]);

        Http::fake([
            'news.google.com/*' => Http::response($this->rssFeed([
                [
                    'guid' => 'ok',
                    'title' => 'Acme Co raised $20M Series B',
                    'link' => 'https://news.example.com/ok',
                    'pubDate' => 'Mon, 18 May 2026 10:00:00 +0000',
                ],
                [
                    'guid' => 'noise',
                    'title' => 'Acme Co launches new product line',
                    'link' => 'https://news.example.com/noise',
                    'pubDate' => 'Tue, 19 May 2026 10:00:00 +0000',
                ],
            ]), 200, ['Content-Type' => 'application/rss+xml']),
        ]);

        Artisan::call('signals:funding:news', ['--tenant' => 'primary']);

        $this->assertSame(1, FundingCandidate::query()->where('company_id', $company->id)->count());
        $this->assertSame(
            'Acme Co raised $20M Series B',
            FundingCandidate::query()->where('company_id', $company->id)->value('title'),
        );
    }

    #[Test]
    public function idempotent_rerun_does_not_duplicate_rows(): void
    {
        $company = Company::factory()->create([
            'name' => 'Acme Co',
            'is_active' => true,
        ]);

        Http::fake([
            'news.google.com/*' => Http::response($this->rssFeed([
                [
                    'guid' => 'g1',
                    'title' => 'Acme Co raised $20M Series B',
                    'link' => 'https://news.example.com/g1',
                    'pubDate' => 'Mon, 18 May 2026 10:00:00 +0000',
                ],
            ]), 200, ['Content-Type' => 'application/rss+xml']),
        ]);

        Artisan::call('signals:funding:news', ['--tenant' => 'primary']);
        Artisan::call('signals:funding:news', ['--tenant' => 'primary']);

        $this->assertSame(1, FundingCandidate::query()->where('company_id', $company->id)->count());
    }

    #[Test]
    public function google_news_article_ids_longer_than_255_characters_are_stored(): void
    {
        $company = Company::factory()->create(['name' => 'Stripe', 'is_active' => true]);
        $guid = 'CBMi'.str_repeat('wAFBVV95cUxOdFoxV2t6', 13);

        Http::fake([
            'news.google.com/*' => Http::response($this->rssFeed([
                [
                    'guid' => $guid,
                    'title' => 'Stripe lining up funding round - CNBC',
                    'link' => 'https://news.google.com/rss/articles/'.$guid.'?oc=5',
                    'pubDate' => 'Tue, 10 Feb 2026 08:00:00 GMT',
                ],
            ]), 200, ['Content-Type' => 'application/rss+xml']),
        ]);

        $this->assertGreaterThan(255, strlen($guid));
        $this->assertSame(0, Artisan::call('signals:funding:news', ['--tenant' => 'primary']));
        $this->assertSame($guid, FundingCandidate::query()->where('company_id', $company->id)->value('external_id'));
    }

    #[Test]
    public function an_article_that_fails_to_save_is_skipped_and_the_run_continues(): void
    {
        $company = Company::factory()->create(['name' => 'Acme Co', 'is_active' => true]);
        // Incompressible and larger than a Postgres btree index entry allows,
        // so the insert fails on the unique index.
        $oversized = base64_encode(random_bytes(6_000));

        Http::fake([
            'news.google.com/*' => Http::response($this->rssFeed([
                [
                    'guid' => $oversized,
                    'title' => 'Acme Co raised $5M seed',
                    'link' => 'https://news.example.com/bad',
                    'pubDate' => 'Mon, 18 May 2026 10:00:00 +0000',
                ],
                [
                    'guid' => 'good',
                    'title' => 'Acme Co raised $20M Series B',
                    'link' => 'https://news.example.com/good',
                    'pubDate' => 'Tue, 19 May 2026 10:00:00 +0000',
                ],
            ]), 200, ['Content-Type' => 'application/rss+xml']),
        ]);

        $exitCode = Artisan::call('signals:funding:news', ['--tenant' => 'primary']);
        $summary = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame(1, $summary['errors']);
        $this->assertSame(1, $summary['candidates_new']);
        $this->assertSame(
            ['good'],
            FundingCandidate::query()->where('company_id', $company->id)->pluck('external_id')->all(),
        );
    }

    #[Test]
    public function articles_older_than_twelve_months_or_undated_are_skipped(): void
    {
        $company = Company::factory()->create(['name' => 'Acme Co', 'is_active' => true]);

        Http::fake([
            'news.google.com/*' => Http::response($this->rssFeed([
                ['guid' => 'recent', 'title' => 'Acme Co raised $20M Series B', 'link' => 'https://news.example.com/1', 'pubDate' => 'Mon, 10 Nov 2025 10:00:00 +0000'],
                ['guid' => 'old', 'title' => 'Acme Co raised $5M Series A', 'link' => 'https://news.example.com/2', 'pubDate' => 'Wed, 05 Nov 2025 10:00:00 +0000'],
                ['guid' => 'undated', 'title' => 'Acme Co raised $1M seed', 'link' => 'https://news.example.com/3', 'pubDate' => ''],
            ]), 200, ['Content-Type' => 'application/rss+xml']),
        ]);

        Artisan::call('signals:funding:news', ['--tenant' => 'primary']);

        $this->assertSame(['recent'], FundingCandidate::query()->where('company_id', $company->id)->pluck('external_id')->all());
    }

    #[Test]
    public function headlines_that_do_not_name_the_company_are_skipped(): void
    {
        $laravel = Company::factory()->create(['name' => 'Laravel', 'is_active' => true]);
        $wex = Company::factory()->create(['name' => 'WEX Inc.', 'is_active' => true]);

        Http::fake([
            'news.google.com/*' => Http::sequence()
                ->push($this->rssFeed([
                    ['guid' => 'other-company', 'title' => 'CodeRabbit raised $60M and celebrated with a short film - Laravel News', 'link' => 'https://news.example.com/1', 'pubDate' => 'Tue, 11 Nov 2025 10:00:00 +0000'],
                    ['guid' => 'named', 'title' => 'Laravel raised $57M Series A - TechCrunch', 'link' => 'https://news.example.com/2', 'pubDate' => 'Tue, 11 Nov 2025 10:00:00 +0000'],
                ]), 200, ['Content-Type' => 'application/rss+xml'])
                ->push($this->rssFeed([
                    ['guid' => 'substring', 'title' => 'Wexford startup raised $3M', 'link' => 'https://news.example.com/3', 'pubDate' => 'Tue, 11 Nov 2025 10:00:00 +0000'],
                    ['guid' => 'wex', 'title' => 'WEX closes round with new lenders', 'link' => 'https://news.example.com/4', 'pubDate' => 'Tue, 11 Nov 2025 10:00:00 +0000'],
                ]), 200, ['Content-Type' => 'application/rss+xml']),
        ]);

        Artisan::call('signals:funding:news', ['--tenant' => 'primary']);

        $this->assertSame(['named'], FundingCandidate::query()->where('company_id', $laravel->id)->pluck('external_id')->all());
        $this->assertSame(['wex'], FundingCandidate::query()->where('company_id', $wex->id)->pluck('external_id')->all());
    }

    #[Test]
    public function company_directory_sites_are_skipped(): void
    {
        $company = Company::factory()->create(['name' => 'Vercel', 'is_active' => true]);

        Http::fake([
            'news.google.com/*' => Http::response($this->rssFeed([
                ['guid' => 'tracxn', 'title' => 'Vercel - 2026 Funding Rounds &amp; List of Investors - Tracxn', 'link' => 'https://news.example.com/1', 'pubDate' => 'Sat, 10 Oct 2026 10:00:00 +0000', 'source' => 'https://tracxn.com'],
                ['guid' => 'cb-insights', 'title' => 'Vercel raised $250M Series E - CB Insights', 'link' => 'https://news.example.com/2', 'pubDate' => 'Sat, 10 Oct 2026 10:00:00 +0000'],
                ['guid' => 'news', 'title' => 'Vercel raised $250M Series E - Fortune', 'link' => 'https://news.example.com/3', 'pubDate' => 'Sat, 10 Oct 2026 10:00:00 +0000', 'source' => 'https://fortune.com'],
            ]), 200, ['Content-Type' => 'application/rss+xml']),
        ]);

        Artisan::call('signals:funding:news', ['--tenant' => 'primary']);

        $this->assertSame(['news'], FundingCandidate::query()->where('company_id', $company->id)->pluck('external_id')->all());
    }

    #[Test]
    public function unreviewed_candidates_that_no_longer_pass_the_filters_are_removed(): void
    {
        $company = Company::factory()->create(['name' => 'Airbnb', 'is_active' => true]);
        $noise = FundingCandidate::factory()->create([
            'company_id' => $company->id,
            'title' => 'Airbnb price target raised to $230 from $210 at Oppenheimer - Yahoo Finance',
            'published_at' => CarbonImmutable::parse('2026-11-06'),
        ]);
        $tooOld = FundingCandidate::factory()->create([
            'company_id' => $company->id,
            'title' => 'Airbnb raises $1B Series F - TechCrunch',
            'published_at' => CarbonImmutable::parse('2020-04-06'),
        ]);
        $kept = FundingCandidate::factory()->create([
            'company_id' => $company->id,
            'title' => 'Airbnb closes round led by Sequoia - Reuters',
            'published_at' => CarbonImmutable::parse('2026-10-01'),
        ]);
        $dismissedNoise = FundingCandidate::factory()->create([
            'company_id' => $company->id,
            'status' => FundingCandidate::STATUS_DISMISSED,
            'title' => 'Airbnb Raised Guidance While Travel Rivals Got Cautious - Money Morning',
            'published_at' => CarbonImmutable::parse('2026-11-06'),
        ]);

        Http::fake(['news.google.com/*' => Http::response($this->rssFeed([]), 200, ['Content-Type' => 'application/rss+xml'])]);

        Artisan::call('signals:funding:news', ['--tenant' => 'primary']);
        $summary = json_decode(trim(Artisan::output()), true);

        $this->assertSame(2, $summary['candidates_pruned']);
        $this->assertNull(FundingCandidate::query()->find($noise->id));
        $this->assertNull(FundingCandidate::query()->find($tooOld->id));
        $this->assertNotNull(FundingCandidate::query()->find($kept->id));
        $this->assertNotNull(FundingCandidate::query()->find($dismissedNoise->id));
    }

    /**
     * @param  list<array{guid: string, title: string, link: string, pubDate: string, source?: string}>  $items
     */
    private function rssFeed(array $items): string
    {
        $itemXml = '';
        foreach ($items as $item) {
            $source = isset($item['source'])
                ? '<source url="'.$item['source'].'">'.parse_url($item['source'], PHP_URL_HOST).'</source>'
                : '';
            $itemXml .= <<<XML
                <item>
                  <guid>{$item['guid']}</guid>
                  <title>{$item['title']}</title>
                  <link>{$item['link']}</link>
                  <pubDate>{$item['pubDate']}</pubDate>
                  {$source}
                </item>
                XML;
        }

        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <rss version="2.0">
              <channel>
                <title>Google News</title>
                <link>https://news.google.com</link>
                <description>Search results</description>
                {$itemXml}
              </channel>
            </rss>
            XML;
    }
}
