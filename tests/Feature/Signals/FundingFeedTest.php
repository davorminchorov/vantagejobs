<?php

declare(strict_types=1);

namespace Tests\Feature\Signals;

use App\Models\FundingAnnouncement;
use App\Signals\Funding\FundingFeedSource;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class FundingFeedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-11-07 12:00:00');
        app(TenantContext::class)->setCurrent(Tenant::factory()->create(['slug' => 'primary']));
    }

    #[Test]
    public function funding_headlines_are_stored_with_company_round_and_amount(): void
    {
        $this->fakeFeeds([
            FundingFeedSource::TechCrunch->url() => [
                ['guid' => 'tc-1', 'title' => 'Northwood Space raises $375M Series C at a $2B valuation', 'pubDate' => 'Mon, 02 Nov 2026 10:00:00 +0000'],
                ['guid' => 'tc-2', 'title' => 'TechCrunch Founder Summit 2026: Doors open at 9 a.m.', 'pubDate' => 'Mon, 02 Nov 2026 10:00:00 +0000'],
                ['guid' => 'tc-3', 'title' => 'Fal doubles valuation to $16B', 'pubDate' => 'Mon, 02 Nov 2026 10:00:00 +0000'],
                ['guid' => 'tc-old', 'title' => 'Oldco raises $5M seed round', 'pubDate' => 'Mon, 03 Aug 2026 10:00:00 +0000'],
            ],
            FundingFeedSource::Uktn->url() => [
                ['guid' => 'uk-1', 'title' => 'Cambridge biotech startup Neela raises &#163;2.1m to turn waste into aviation fuel', 'pubDate' => 'Thu, 08 Oct 2026 10:00:00 +0000'],
            ],
        ]);

        $this->assertSame(0, Artisan::call('signals:funding:feed', ['--tenant' => 'primary']));

        $rows = FundingAnnouncement::query()->orderBy('external_id')->get()->keyBy('external_id');
        $this->assertSame(['tc-1', 'uk-1'], $rows->keys()->all());

        $this->assertSame('Northwood Space', $rows['tc-1']->company_name);
        $this->assertSame('northwoodspace', $rows['tc-1']->company_key);
        $this->assertSame('Series C', $rows['tc-1']->stage);
        $this->assertSame(375_000_000, $rows['tc-1']->amount_usd);
        $this->assertSame('techcrunch', $rows['tc-1']->source);

        $this->assertSame('Neela', $rows['uk-1']->company_name);
        $this->assertNull($rows['uk-1']->amount_usd);
        $this->assertSame('£2.1M', $rows['uk-1']->amount_label);
    }

    #[Test]
    public function rerunning_keeps_review_state_and_a_failing_feed_does_not_stop_the_others(): void
    {
        $items = [['guid' => 'tc-1', 'title' => 'Harbinger raises $240M after expanding into defense', 'pubDate' => 'Thu, 05 Nov 2026 10:00:00 +0000']];
        $this->fakeFeeds([FundingFeedSource::TechCrunch->url() => $items], failing: FundingFeedSource::Uktn->url());

        Artisan::call('signals:funding:feed', ['--tenant' => 'primary']);
        FundingAnnouncement::query()->update(['status' => FundingAnnouncement::STATUS_DISMISSED]);
        Artisan::call('signals:funding:feed', ['--tenant' => 'primary']);
        $summary = json_decode(trim(Artisan::output()), true);

        $this->assertSame(1, $summary['errors']);
        $this->assertSame(0, $summary['new']);
        $this->assertSame(1, FundingAnnouncement::query()->count());
        $this->assertSame(FundingAnnouncement::STATUS_DISMISSED, FundingAnnouncement::query()->value('status'));
    }

    /**
     * @param  array<string, list<array{guid: string, title: string, pubDate: string}>>  $feeds
     */
    private function fakeFeeds(array $feeds, ?string $failing = null): void
    {
        $responses = [];
        foreach (FundingFeedSource::cases() as $source) {
            $url = $source->url();
            $responses[$url] = $url === $failing
                ? Http::response('', 503)
                : Http::response($this->rss($feeds[$url] ?? []), 200, ['Content-Type' => 'application/rss+xml']);
        }

        Http::fake($responses);
    }

    /**
     * @param  list<array{guid: string, title: string, pubDate: string}>  $items
     */
    private function rss(array $items): string
    {
        $xml = '';
        foreach ($items as $item) {
            $xml .= "<item><title>{$item['title']}</title><link>https://news.example.com/{$item['guid']}</link>"
                ."<guid>{$item['guid']}</guid><pubDate>{$item['pubDate']}</pubDate></item>";
        }

        return '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>Feed</title>'.$xml.'</channel></rss>';
    }
}
