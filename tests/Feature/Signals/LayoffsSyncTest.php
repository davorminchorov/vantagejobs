<?php

declare(strict_types=1);

namespace Tests\Feature\Signals;

use App\Enums\EventType;
use App\Models\Company;
use App\Models\Event;
use App\Scrapers\Aggregator\CompanyKeyNormalizer;
use App\Signals\Layoffs\LayoffsCsvParser;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class LayoffsSyncTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private string $fixturePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create(['slug' => 'primary']);
        app(TenantContext::class)->setCurrent($this->tenant);

        $this->fixturePath = sys_get_temp_dir().'/layoffs-fixture-'.bin2hex(random_bytes(4)).'.csv';
        file_put_contents($this->fixturePath, $this->fixtureCsv());
    }

    protected function tearDown(): void
    {
        if (is_file($this->fixturePath)) {
            @unlink($this->fixturePath);
        }
        parent::tearDown();
    }

    private function fixtureCsv(): string
    {
        // 5 rows:
        //  1. Acme, Inc. — matches seeded company "Acme Corp"
        //  2. Globex Corporation — matches seeded "Globex"
        //  3. Initech — missing %, has headcount, should still match (no seed → unmatched)
        //  4. WidgetCo — malformed date, skipped by parser
        //  5. UnrelatedCo — no matching company
        return <<<'CSV'
Company,Location_HQ,# Laid Off,%,Date,Industry,Source,Stage,Country,Funds_Raised
"Acme, Inc.",SF,120,0.10,2026-03-01,Retail,layoffs.fyi,Series B,United States,150
Globex Corporation,NYC,500,0.25,2026-04-12,Finance,layoffs.fyi,Series D,United States,800
Initech,Austin,40,,not-a-real-date-but-this-row-is-percent-test-only,Tech,layoffs.fyi,Seed,United States,5
WidgetCo,Boston,10,0.05,not-a-date,Mfg,layoffs.fyi,Series A,United States,12
UnrelatedCo,LA,75,0.15,2026-02-22,Media,layoffs.fyi,Series C,United States,40
CSV;
    }

    private function seedMatchedCompanies(): void
    {
        Company::factory()->create(['name' => 'Acme Corp', 'is_active' => true]);
        Company::factory()->create(['name' => 'Globex', 'is_active' => true]);
        // No company for Initech / WidgetCo / UnrelatedCo.
    }

    #[Test]
    public function creates_events_only_for_matched_companies(): void
    {
        $this->seedMatchedCompanies();

        Artisan::call('signals:layoffs:sync', [
            '--tenant' => 'primary',
            '--source' => 'local',
            '--path' => $this->fixturePath,
        ]);

        $this->assertSame(2, Event::query()->where('type', EventType::LAYOFF->value)->count());

        $acme = Company::query()->where('name', 'Acme Corp')->firstOrFail();
        $acmeEvent = Event::query()->where('company_id', $acme->id)->firstOrFail();
        $this->assertSame(EventType::LAYOFF->value, $acmeEvent->type);
        $body = json_decode((string) $acmeEvent->body, true);
        $this->assertSame(120, $body['headcount']);
        $this->assertEquals(10.0, $body['percent']);
        $this->assertSame('2026-03-01', $body['date']);
        $this->assertSame('layoffs_fyi', $body['source']);
        $this->assertArrayHasKey('signature', $body);
    }

    #[Test]
    public function is_idempotent_on_rerun(): void
    {
        $this->seedMatchedCompanies();

        Artisan::call('signals:layoffs:sync', [
            '--tenant' => 'primary',
            '--source' => 'local',
            '--path' => $this->fixturePath,
        ]);
        Artisan::call('signals:layoffs:sync', [
            '--tenant' => 'primary',
            '--source' => 'local',
            '--path' => $this->fixturePath,
        ]);

        $this->assertSame(2, Event::query()->where('type', EventType::LAYOFF->value)->count());
    }

    #[Test]
    public function skips_rows_with_malformed_dates(): void
    {
        // No seeded companies — we only need to confirm the parser doesn't
        // explode on WidgetCo's bad date. Parser-level guarantee.
        $parser = new LayoffsCsvParser(new CompanyKeyNormalizer);
        $records = $parser->parse(file_get_contents($this->fixturePath));

        // Of 5 rows: WidgetCo + Initech ("not-a-real-date-but-..." date)
        // are dropped. Acme/Globex/UnrelatedCo remain.
        $names = array_map(static fn ($r): string => $r->companyName, $records);
        $this->assertContains('Acme, Inc.', $names);
        $this->assertContains('Globex Corporation', $names);
        $this->assertContains('UnrelatedCo', $names);
        $this->assertNotContains('WidgetCo', $names);
    }

    #[Test]
    public function unmatched_companies_are_silently_skipped(): void
    {
        $this->seedMatchedCompanies();

        Artisan::call('signals:layoffs:sync', [
            '--tenant' => 'primary',
            '--source' => 'local',
            '--path' => $this->fixturePath,
        ]);

        // UnrelatedCo has no company row, so no event for it.
        $this->assertSame(0, Event::query()
            ->where('type', EventType::LAYOFF->value)
            ->where('title', 'like', '%UnrelatedCo%')
            ->count());
    }

    #[Test]
    public function record_normalises_company_key(): void
    {
        $parser = new LayoffsCsvParser(new CompanyKeyNormalizer);
        $records = $parser->parse(file_get_contents($this->fixturePath));

        $byName = [];
        foreach ($records as $r) {
            $byName[$r->companyName] = $r;
        }

        $this->assertSame('acme', $byName['Acme, Inc.']->normalizedKey);
        $this->assertSame('globex', $byName['Globex Corporation']->normalizedKey);
    }

    #[Test]
    public function kaggle_source_returns_failure_until_implemented(): void
    {
        $exit = Artisan::call('signals:layoffs:sync', [
            '--tenant' => 'primary',
            '--source' => 'kaggle',
        ]);

        $this->assertNotSame(0, $exit);
    }
}
