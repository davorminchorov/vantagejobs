<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Enums\EventType;
use App\Models\Company;
use App\Models\Event;
use App\Models\User;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CsvImportTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->setCurrent($this->tenant);

        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    #[Test]
    public function unauthenticated_users_redirected(): void
    {
        $this->get('/import')->assertRedirect('/login');
    }

    #[Test]
    public function upload_form_renders(): void
    {
        $this->actingAs($this->user)
            ->get('/import')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Import/Upload'));
    }

    #[Test]
    public function uploading_a_csv_parses_headers_and_redirects_to_map(): void
    {
        $csv = <<<'CSV'
        Name,Website,Tier,Status
        Acme Robotics,https://www.acme.com,A,watching
        Globex,https://globex.test,C,researching
        CSV;

        $file = UploadedFile::fake()->createWithContent('list.csv', $csv);

        $response = $this->actingAs($this->user)
            ->post('/import', ['file' => $file]);

        $response->assertRedirect('/import/map');
        $this->assertSame(['Name', 'Website', 'Tier', 'Status'], session('import.headers'));
        $this->assertCount(2, session('import.rows'));

        // Auto-detected mapping:
        $mapping = session('import.mapping');
        $this->assertSame('Name', $mapping['name']);
        $this->assertSame('Website', $mapping['website']);
        $this->assertSame('Tier', $mapping['tier']);
        $this->assertSame('Status', $mapping['status']);
        $this->assertNull($mapping['careers_url']);
    }

    #[Test]
    public function upload_rejects_non_csv_files(): void
    {
        $file = UploadedFile::fake()->image('not-a-csv.png');

        $this->actingAs($this->user)
            ->from('/import')
            ->post('/import', ['file' => $file])
            ->assertSessionHasErrors('file');
    }

    #[Test]
    public function map_form_renders_when_session_state_is_present(): void
    {
        $this->actingAs($this->user)
            ->withSession([
                'import.headers' => ['Name', 'Website'],
                'import.rows' => [['Acme', 'https://acme.test']],
                'import.mapping' => ['name' => 'Name', 'website' => 'Website', 'careers_url' => null, 'domain' => null, 'tier' => null, 'status' => null],
            ])
            ->get('/import/map')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Import/Map')
                ->has('headers', 2)
                ->has('preview', 1)
                ->where('preview.0.name', 'Acme')
                ->where('preview.0.website', 'https://acme.test'));
    }

    #[Test]
    public function map_form_redirects_to_upload_when_no_session_state(): void
    {
        $this->actingAs($this->user)->get('/import/map')->assertRedirect('/import');
    }

    #[Test]
    public function running_an_import_creates_companies_with_events_and_ats_detection(): void
    {
        $session = [
            'import.headers' => ['Name', 'Website', 'Careers'],
            'import.rows' => [
                ['Acme', 'https://www.acme.com', 'https://jobs.lever.co/acme'],
                ['Globex', 'https://globex.test', ''],
            ],
            'import.mapping' => [
                'name' => 'Name',
                'website' => 'Website',
                'careers_url' => 'Careers',
                'domain' => null,
                'tier' => null,
                'status' => null,
            ],
        ];

        $this->actingAs($this->user)
            ->withSession($session)
            ->post('/import/run', ['mapping' => $session['import.mapping']])
            ->assertRedirect('/companies');

        $this->assertSame(2, Company::query()->count());

        $acme = Company::query()->where('name', 'Acme')->sole();
        $this->assertSame('acme.com', $acme->domain);
        $this->assertSame('lever', $acme->ats_type, 'Auto-detect ran on careers URL.');
        $this->assertSame('acme', $acme->ats_slug);
        $this->assertSame('C', $acme->tier);
        $this->assertSame('researching', $acme->status);

        $this->assertSame(2, Event::query()->where('type', EventType::COMPANY_ADDED->value)->count());
    }

    #[Test]
    public function running_a_second_time_skips_companies_with_existing_domain(): void
    {
        Company::factory()->create(['name' => 'Acme prior', 'domain' => 'acme.com']);

        $session = [
            'import.headers' => ['Name', 'Website'],
            'import.rows' => [
                ['Acme New', 'https://www.acme.com'],
                ['Beta', 'https://beta.test'],
            ],
            'import.mapping' => [
                'name' => 'Name',
                'website' => 'Website',
                'careers_url' => null,
                'domain' => null,
                'tier' => null,
                'status' => null,
            ],
        ];

        $this->actingAs($this->user)
            ->withSession($session)
            ->post('/import/run', ['mapping' => $session['import.mapping']])
            ->assertRedirect('/companies');

        $this->assertSame(2, Company::query()->count(), 'Original + new Beta only.');
        $this->assertSame(1, Company::query()->where('domain', 'acme.com')->count());
        $this->assertSame('Acme prior', Company::query()->where('domain', 'acme.com')->sole()->name);
    }

    #[Test]
    public function run_fails_validation_when_required_name_column_is_unmapped(): void
    {
        $session = [
            'import.headers' => ['Name', 'Website'],
            'import.rows' => [['Acme', 'https://acme.test']],
            'import.mapping' => [
                'name' => null,
                'website' => 'Website',
                'careers_url' => null,
                'domain' => null,
                'tier' => null,
                'status' => null,
            ],
        ];

        $this->actingAs($this->user)
            ->withSession($session)
            ->from('/import/map')
            ->post('/import/run', ['mapping' => $session['import.mapping']])
            ->assertSessionHasErrors('mapping.name');
    }

    #[Test]
    public function run_clears_session_state_after_success(): void
    {
        $session = [
            'import.headers' => ['Name'],
            'import.rows' => [['Acme']],
            'import.mapping' => ['name' => 'Name', 'website' => null, 'careers_url' => null, 'domain' => null, 'tier' => null, 'status' => null],
        ];

        $this->actingAs($this->user)
            ->withSession($session)
            ->post('/import/run', ['mapping' => $session['import.mapping']]);

        $this->assertNull(session('import.headers'));
        $this->assertNull(session('import.rows'));
    }

    #[Test]
    public function cancel_clears_session_state_and_redirects_to_upload(): void
    {
        $this->actingAs($this->user)
            ->withSession([
                'import.headers' => ['Name'],
                'import.rows' => [['Acme']],
            ])
            ->post('/import/cancel')
            ->assertRedirect('/import');

        $this->assertNull(session('import.headers'));
    }

    #[Test]
    public function tier_and_status_values_are_normalized(): void
    {
        $session = [
            'import.headers' => ['Name', 'Tier', 'Status'],
            'import.rows' => [
                ['Acme', 'a', 'In Process'],
                ['Beta', 'graveyard', 'WEIRD'],
            ],
            'import.mapping' => [
                'name' => 'Name',
                'website' => null,
                'careers_url' => null,
                'domain' => null,
                'tier' => 'Tier',
                'status' => 'Status',
            ],
        ];

        $this->actingAs($this->user)
            ->withSession($session)
            ->post('/import/run', ['mapping' => $session['import.mapping']]);

        $acme = Company::query()->where('name', 'Acme')->sole();
        $this->assertSame('A', $acme->tier);
        $this->assertSame('in_process', $acme->status);

        $beta = Company::query()->where('name', 'Beta')->sole();
        $this->assertSame('graveyard', $beta->tier);
        $this->assertSame('researching', $beta->status, 'Unknown status falls back to default.');
    }
}
