<?php

declare(strict_types=1);

namespace Tests\Feature\Signals;

use App\Enums\EventType;
use App\Models\Company;
use App\Models\CompanyBlogPost;
use App\Models\Event;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class BlogPollTest extends TestCase
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
    public function new_posts_create_blog_post_events(): void
    {
        $company = Company::factory()->create([
            'is_active' => true,
            'eng_blog_rss_url' => 'https://eng.example.com/rss',
        ]);

        Http::fake([
            'eng.example.com/rss' => Http::response($this->rssFeed([
                ['guid' => 'post-1', 'title' => 'Shipping faster', 'link' => 'https://eng.example.com/p/1', 'pubDate' => 'Mon, 18 May 2026 10:00:00 +0000', 'description' => 'How we ship.'],
                ['guid' => 'post-2', 'title' => 'On reliability', 'link' => 'https://eng.example.com/p/2', 'pubDate' => 'Tue, 19 May 2026 10:00:00 +0000', 'description' => 'SLOs etc.'],
            ]), 200, ['Content-Type' => 'application/rss+xml']),
        ]);

        Artisan::call('signals:blog:poll', ['--tenant' => 'primary']);

        $this->assertSame(2, CompanyBlogPost::query()->where('company_id', $company->id)->count());

        $events = Event::query()
            ->where('company_id', $company->id)
            ->where('type', EventType::BLOG_POST->value)
            ->get();

        $this->assertCount(2, $events);
        $this->assertEqualsCanonicalizing(
            ['Shipping faster', 'On reliability'],
            $events->pluck('title')->all(),
        );
    }

    #[Test]
    public function idempotent_rerun_adds_no_new_rows(): void
    {
        $company = Company::factory()->create([
            'is_active' => true,
            'eng_blog_rss_url' => 'https://eng.example.com/rss',
        ]);

        Http::fake([
            'eng.example.com/rss' => Http::response($this->rssFeed([
                ['guid' => 'post-1', 'title' => 'Shipping faster', 'link' => 'https://eng.example.com/p/1', 'pubDate' => 'Mon, 18 May 2026 10:00:00 +0000', 'description' => 'How we ship.'],
            ]), 200, ['Content-Type' => 'application/rss+xml']),
        ]);

        Artisan::call('signals:blog:poll', ['--tenant' => 'primary']);
        Artisan::call('signals:blog:poll', ['--tenant' => 'primary']);

        $this->assertSame(1, CompanyBlogPost::query()->where('company_id', $company->id)->count());
        $this->assertSame(1, Event::query()
            ->where('company_id', $company->id)
            ->where('type', EventType::BLOG_POST->value)
            ->count());
    }

    #[Test]
    public function fetch_failure_logs_but_does_not_abort_other_companies(): void
    {
        $broken = Company::factory()->create([
            'is_active' => true,
            'eng_blog_rss_url' => 'https://broken.example.com/rss',
        ]);
        $ok = Company::factory()->create([
            'is_active' => true,
            'eng_blog_rss_url' => 'https://eng.example.com/rss',
        ]);

        Http::fake([
            'broken.example.com/*' => Http::response('boom', 500),
            'eng.example.com/rss' => Http::response($this->rssFeed([
                ['guid' => 'post-1', 'title' => 'Still here', 'link' => 'https://eng.example.com/p/1', 'pubDate' => 'Mon, 18 May 2026 10:00:00 +0000', 'description' => null],
            ]), 200, ['Content-Type' => 'application/rss+xml']),
        ]);

        Log::spy();

        Artisan::call('signals:blog:poll', ['--tenant' => 'primary']);

        $this->assertSame(0, CompanyBlogPost::query()->where('company_id', $broken->id)->count());
        $this->assertSame(1, CompanyBlogPost::query()->where('company_id', $ok->id)->count());

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message, array $context = []) => $message === 'signals.blog.feed_fetch_failed' && ($context['company_id'] ?? null) === $broken->id)
            ->atLeast()->once();
    }

    #[Test]
    public function parses_atom_feeds(): void
    {
        $company = Company::factory()->create([
            'is_active' => true,
            'eng_blog_rss_url' => 'https://atom.example.com/feed',
        ]);

        Http::fake([
            'atom.example.com/feed' => Http::response($this->atomFeed([
                ['id' => 'urn:uuid:abc', 'title' => 'Atom one', 'href' => 'https://atom.example.com/posts/abc', 'published' => '2026-05-18T10:00:00Z', 'summary' => 'Hello atom.'],
                ['id' => 'urn:uuid:def', 'title' => 'Atom two', 'href' => 'https://atom.example.com/posts/def', 'published' => '2026-05-19T10:00:00Z', 'summary' => 'Second.'],
            ]), 200, ['Content-Type' => 'application/atom+xml']),
        ]);

        Artisan::call('signals:blog:poll', ['--tenant' => 'primary']);

        $rows = CompanyBlogPost::query()->where('company_id', $company->id)->orderBy('external_id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame('urn:uuid:abc', $rows[0]->external_id);
        $this->assertSame('Atom one', $rows[0]->title);
        $this->assertSame('https://atom.example.com/posts/abc', $rows[0]->url);
        $this->assertNotNull($rows[0]->published_at);

        $this->assertSame(2, Event::query()
            ->where('company_id', $company->id)
            ->where('type', EventType::BLOG_POST->value)
            ->count());
    }

    /**
     * @param  list<array{guid: string, title: string, link: string, pubDate: string, description: ?string}>  $items
     */
    private function rssFeed(array $items): string
    {
        $itemXml = '';
        foreach ($items as $item) {
            $desc = $item['description'] === null ? '' : '<description><![CDATA['.$item['description'].']]></description>';
            $itemXml .= <<<XML
                <item>
                  <guid>{$item['guid']}</guid>
                  <title>{$item['title']}</title>
                  <link>{$item['link']}</link>
                  <pubDate>{$item['pubDate']}</pubDate>
                  {$desc}
                </item>
                XML;
        }

        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <rss version="2.0">
              <channel>
                <title>Example Eng</title>
                <link>https://eng.example.com</link>
                <description>Engineering blog</description>
                {$itemXml}
              </channel>
            </rss>
            XML;
    }

    /**
     * @param  list<array{id: string, title: string, href: string, published: string, summary: string}>  $entries
     */
    private function atomFeed(array $entries): string
    {
        $entryXml = '';
        foreach ($entries as $entry) {
            $entryXml .= <<<XML
                <entry>
                  <id>{$entry['id']}</id>
                  <title>{$entry['title']}</title>
                  <link rel="alternate" href="{$entry['href']}"/>
                  <published>{$entry['published']}</published>
                  <summary>{$entry['summary']}</summary>
                </entry>
                XML;
        }

        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <feed xmlns="http://www.w3.org/2005/Atom">
              <title>Atom Example</title>
              <id>https://atom.example.com/</id>
              <updated>2026-05-19T10:00:00Z</updated>
              {$entryXml}
            </feed>
            XML;
    }
}
