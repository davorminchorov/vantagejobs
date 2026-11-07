<?php

declare(strict_types=1);

namespace Tests\Feature\Signals;

use App\Models\Company;
use App\Models\FundingCandidate;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
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

    /**
     * @param  list<array{guid: string, title: string, link: string, pubDate: string}>  $items
     */
    private function rssFeed(array $items): string
    {
        $itemXml = '';
        foreach ($items as $item) {
            $itemXml .= <<<XML
                <item>
                  <guid>{$item['guid']}</guid>
                  <title>{$item['title']}</title>
                  <link>{$item['link']}</link>
                  <pubDate>{$item['pubDate']}</pubDate>
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
