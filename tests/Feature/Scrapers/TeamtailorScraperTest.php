<?php

declare(strict_types=1);

namespace Tests\Feature\Scrapers;

use App\Enums\AtsType;
use App\Enums\EventType;
use App\Models\Company;
use App\Models\Event;
use App\Models\Role;
use App\Scrapers\RoleDiffer;
use App\Scrapers\Teamtailor\Requests\JobsRssRequest;
use App\Scrapers\Teamtailor\TeamtailorScraper;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Laravel\Facades\Saloon;
use Tests\TestCase;

final class TeamtailorScraperTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private TeamtailorScraper $scraper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->setCurrent($this->tenant);

        $this->company = Company::factory()->create([
            'ats_type' => AtsType::TEAMTAILOR->value,
            'ats_slug' => 'acme',
        ]);

        $this->scraper = new TeamtailorScraper(new RoleDiffer());
    }

    private function fixture(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
  <channel>
    <title>Acme Jobs</title>
    <link>https://acme.teamtailor.com</link>
    <description>Open positions</description>
    <item>
      <title>Senior Backend Engineer</title>
      <link>https://acme.teamtailor.com/jobs/12345-senior-backend-engineer</link>
      <description><![CDATA[<p>Backend role description.</p>]]></description>
      <pubDate>Mon, 12 May 2026 10:00:00 +0000</pubDate>
      <guid isPermaLink="false">12345</guid>
      <category>Engineering</category>
    </item>
    <item>
      <title>Product Designer</title>
      <link>https://acme.teamtailor.com/jobs/12346-product-designer</link>
      <description><![CDATA[<p>Design role description.</p>]]></description>
      <pubDate>Tue, 13 May 2026 10:00:00 +0000</pubDate>
      <guid isPermaLink="false">12346</guid>
      <category>Design</category>
    </item>
    <item>
      <title>Sales Development Representative</title>
      <link>https://acme.teamtailor.com/jobs/12347-sdr</link>
      <description><![CDATA[<p>SDR role description.</p>]]></description>
      <pubDate>Wed, 14 May 2026 10:00:00 +0000</pubDate>
      <guid isPermaLink="false">12347</guid>
      <category>Sales</category>
    </item>
  </channel>
</rss>
XML;
    }

    #[Test]
    public function happy_path_inserts_roles_and_emits_new_events(): void
    {
        Saloon::fake([
            JobsRssRequest::class => MockResponse::make(body: $this->fixture(), status: 200),
        ]);

        $result = $this->scraper->run($this->company);

        $this->assertFalse($result->hasErrors());
        $this->assertSame(3, $result->fetched);
        $this->assertSame(3, $result->newCount);
        $this->assertSame(0, $result->closedCount);

        $this->assertSame(3, Role::query()->where('company_id', $this->company->id)->count());
        $this->assertSame(3, Event::query()->where('type', EventType::NEW_ROLE->value)->count());

        $role = Role::query()->where('external_id', '12345')->firstOrFail();
        $this->assertSame('Senior Backend Engineer', $role->title);
        $this->assertSame('https://acme.teamtailor.com/jobs/12345-senior-backend-engineer', $role->url);
        $this->assertSame('Engineering', $role->department);

        $fresh = $this->company->fresh();
        $this->assertNotNull($fresh->last_checked_at);
    }

    #[Test]
    public function rerunning_against_identical_payload_is_idempotent(): void
    {
        Saloon::fake([
            JobsRssRequest::class => MockResponse::make(body: $this->fixture(), status: 200),
        ]);

        $first = $this->scraper->run($this->company);
        $this->assertSame(3, $first->newCount);
        $this->assertSame(3, Event::query()->count());

        Saloon::fake([
            JobsRssRequest::class => MockResponse::make(body: $this->fixture(), status: 200),
        ]);

        $second = $this->scraper->run($this->company);

        $this->assertSame(0, $second->newCount);
        $this->assertSame(0, $second->closedCount);
        $this->assertSame(3, $second->fetched);
        $this->assertSame(3, Event::query()->count(), 'Re-running must not duplicate events.');
    }

    #[Test]
    public function http_error_returns_result_with_errors(): void
    {
        Saloon::fake([
            JobsRssRequest::class => MockResponse::make(body: '', status: 500),
        ]);

        $result = $this->scraper->run($this->company);

        $this->assertTrue($result->hasErrors());
        $this->assertSame(0, $result->fetched);
        $this->assertSame(0, Role::query()->count());
    }

    #[Test]
    public function exception_during_fetch_returns_result_with_errors(): void
    {
        Saloon::fake([
            JobsRssRequest::class => function (PendingRequest $request): MockResponse {
                throw new \RuntimeException('boom');
            },
        ]);

        $result = $this->scraper->run($this->company);

        $this->assertTrue($result->hasErrors());
        $this->assertSame(0, $result->fetched);
        $this->assertSame(0, Role::query()->count());
    }

    #[Test]
    public function zero_fetch_with_open_roles_refuses_to_mass_close(): void
    {
        $openRole = Role::factory()->create([
            'company_id' => $this->company->id,
            'source' => AtsType::TEAMTAILOR->value,
            'is_open' => true,
        ]);

        Saloon::fake([
            JobsRssRequest::class => MockResponse::make(
                body: '<?xml version="1.0"?><rss version="2.0"><channel></channel></rss>',
                status: 200,
            ),
        ]);

        $result = $this->scraper->run($this->company);

        $this->assertTrue($result->hasErrors());
        $this->assertSame(0, $result->closedCount);
        $this->assertTrue($openRole->fresh()->is_open);
    }

    #[Test]
    public function regional_host_cached_on_ats_host_is_preferred_over_the_global_default(): void
    {
        $this->company->ats_host = 'acme.na.teamtailor.com';
        $this->company->save();

        $requestedUrl = null;
        Saloon::fake([
            JobsRssRequest::class => function (PendingRequest $request) use (&$requestedUrl): MockResponse {
                $requestedUrl = $request->getUrl();

                return MockResponse::make(body: $this->fixture(), status: 200);
            },
        ]);

        $result = $this->scraper->run($this->company);

        $this->assertFalse($result->hasErrors());
        $this->assertStringStartsWith('https://acme.na.teamtailor.com/', (string) $requestedUrl);
    }

    #[Test]
    public function global_host_is_used_when_no_ats_host_is_cached(): void
    {
        $requestedUrl = null;
        Saloon::fake([
            JobsRssRequest::class => function (PendingRequest $request) use (&$requestedUrl): MockResponse {
                $requestedUrl = $request->getUrl();

                return MockResponse::make(body: $this->fixture(), status: 200);
            },
        ]);

        $this->scraper->run($this->company);

        $this->assertStringStartsWith('https://acme.teamtailor.com/', (string) $requestedUrl);
    }
}
