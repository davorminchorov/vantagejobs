<?php

declare(strict_types=1);

namespace Tests\Feature\Insights;

use App\Enums\EventType;
use App\Models\Company;
use App\Models\Event;
use App\Models\FundingAnnouncement;
use App\Models\User;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class RecentlyFundedTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-11-07 12:00:00');
        $tenant = Tenant::factory()->create();
        app(TenantContext::class)->setCurrent($tenant);
        $this->user = User::factory()->create(['tenant_id' => $tenant->id]);
    }

    #[Test]
    public function headlines_are_grouped_per_company_and_tracked_companies_are_hidden_by_default(): void
    {
        $this->announcement('Kabilio', 'tech_eu', '2026-11-05', ['stage' => null, 'amount_label' => '€10M']);
        $this->announcement('Kabilio', 'tech_funding_news', '2026-11-06', ['stage' => 'Series A', 'amount_label' => null]);
        $this->announcement('Harbinger', 'techcrunch', '2026-11-04');
        $this->announcement(null, 'tech_eu', '2026-11-03', ['title' => "Denmark's EIFO joins TEKEVER's \$580M Series D"]);
        $this->announcement('Oldco', 'techcrunch', '2026-07-01');
        $this->announcement('Gone', 'techcrunch', '2026-11-06', ['status' => FundingAnnouncement::STATUS_DISMISSED]);
        $tracked = Company::factory()->create(['name' => 'Harbinger Inc.']);

        $props = $this->page('/insights/recently-funded');

        $this->assertSame(['region' => 'all', 'round' => 'all', 'tracked' => 'hide'], $props['filters']);
        $this->assertSame(['Kabilio', null], array_column($props['groups'], 'company_name'));

        $kabilio = $props['groups'][0];
        $this->assertSame('Series A', $kabilio['stage']);
        $this->assertSame('€10M', $kabilio['amount_label']);
        $this->assertSame(['global', 'europe'], $kabilio['regions']);
        $this->assertCount(2, $kabilio['headlines']);
        $this->assertSame('Tech Funding News', $kabilio['headlines'][0]['source']);

        $all = $this->page('/insights/recently-funded?tracked=show');
        $harbinger = collect($all['groups'])->firstWhere('company_name', 'Harbinger');
        $this->assertSame(['id' => $tracked->id, 'name' => 'Harbinger Inc.'], $harbinger['tracked_company']);
    }

    #[Test]
    public function region_and_round_filters_narrow_the_list(): void
    {
        $this->announcement('Neela', 'uktn', '2026-11-05', ['stage' => 'Seed']);
        $this->announcement('Kabilio', 'tech_eu', '2026-11-05', ['stage' => 'Series B']);
        $this->announcement('Harbinger', 'techcrunch', '2026-11-05', ['stage' => 'Series C']);

        $this->assertSame(['Kabilio', 'Neela'], $this->names('/insights/recently-funded?region=europe'));
        $this->assertSame(['Neela'], $this->names('/insights/recently-funded?region=uk'));
        $this->assertSame(['Harbinger'], $this->names('/insights/recently-funded?region=us'));
        $this->assertSame(['Kabilio'], $this->names('/insights/recently-funded?round=early'));
        $this->assertSame(['Neela'], $this->names('/insights/recently-funded?round=seed&region=europe'));
        $this->assertSame(['all', 'all'], array_values(array_intersect_key(
            $this->page('/insights/recently-funded?region=mars&round=bogus')['filters'],
            ['region' => 1, 'round' => 1],
        )));
    }

    #[Test]
    public function dismiss_hides_every_headline_about_the_company(): void
    {
        $first = $this->announcement('Kabilio', 'tech_eu', '2026-11-05');
        $this->announcement('Kabilio', 'techcrunch', '2026-11-06');
        $other = $this->announcement('Harbinger', 'techcrunch', '2026-11-06');

        $this->actingAs($this->user)->post("/insights/recently-funded/{$first->id}/dismiss")->assertRedirect();

        $this->assertSame(2, FundingAnnouncement::query()->where('company_key', 'kabilio')->where('status', FundingAnnouncement::STATUS_DISMISSED)->count());
        $this->assertSame(FundingAnnouncement::STATUS_NEW, $other->refresh()->status);
    }

    #[Test]
    public function track_prefills_the_add_form_and_saving_records_the_round(): void
    {
        Queue::fake();
        $announcement = $this->announcement('Kabilio', 'tech_eu', '2026-11-05', [
            'stage' => 'Series A',
            'amount_usd' => 10_000_000,
            'title' => 'Kabilio raises $10M Series A',
            'url' => 'https://news.example.com/kabilio',
        ]);
        $sibling = $this->announcement('Kabilio', 'techcrunch', '2026-11-06');

        $this->actingAs($this->user)
            ->get("/companies/create?funding_announcement={$announcement->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Companies/Create')
                ->where('initial.name', 'Kabilio')
                ->where('initial.funding_announcement_id', $announcement->id)
                ->where('from_announcement.source', 'tech.eu'));

        $this->actingAs($this->user)
            ->post('/companies', ['name' => 'Kabilio', 'website' => 'https://kabilio.com', 'funding_announcement_id' => $announcement->id])
            ->assertRedirect('/companies')
            ->assertSessionHasNoErrors();

        $company = Company::query()->where('name', 'Kabilio')->sole();
        $this->assertSame('Series A', $company->funding_stage);
        $this->assertSame(10_000_000, $company->funding_last_amount_usd);
        $this->assertSame('2026-11-05', $company->funding_last_raised_at?->toDateString());

        $event = Event::query()->where('company_id', $company->id)->where('type', EventType::FUNDING->value)->sole();
        $this->assertSame('Kabilio raises $10M Series A', $event->title);

        foreach ([$announcement, $sibling] as $row) {
            $row->refresh();
            $this->assertSame(FundingAnnouncement::STATUS_TRACKED, $row->status);
            $this->assertSame($company->id, $row->company_id);
        }
    }

    #[Test]
    public function create_page_without_an_announcement_is_unchanged(): void
    {
        $this->actingAs($this->user)
            ->get('/companies/create?funding_announcement=abc')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('from_announcement', null));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function announcement(?string $name, string $source, string $publishedOn, array $overrides = []): FundingAnnouncement
    {
        return FundingAnnouncement::factory()->create([
            'company_name' => $name,
            'company_key' => $name !== null ? mb_strtolower($name) : null,
            'source' => $source,
            'published_at' => CarbonImmutable::parse($publishedOn.' 09:00:00'),
            ...$overrides,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function page(string $url): array
    {
        return $this->actingAs($this->user)->get($url)->assertOk()->viewData('page')['props'];
    }

    /**
     * @return list<string|null>
     */
    private function names(string $url): array
    {
        $names = array_column($this->page($url)['groups'], 'company_name');
        sort($names);

        return $names;
    }
}
