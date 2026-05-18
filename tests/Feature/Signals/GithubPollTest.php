<?php

declare(strict_types=1);

namespace Tests\Feature\Signals;

use App\Models\Company;
use App\Models\CompanySnapshot;
use App\Signals\Github\GithubOrgScorer;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class GithubPollTest extends TestCase
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
    public function score_is_written_to_todays_snapshot_row(): void
    {
        $company = Company::factory()->create([
            'is_active' => true,
            'github_org' => 'acme',
        ]);

        $this->fakeOrg('acme', publicRepos: 100, activeRepos: 8, newRepos: 5);

        Artisan::call('signals:github:poll', ['--tenant' => 'primary']);

        $today = CarbonImmutable::now('UTC')->toDateString();
        $row = CompanySnapshot::query()
            ->where('company_id', $company->id)
            ->where('snapshot_date', $today)
            ->first();

        $this->assertNotNull($row);
        $this->assertNotNull($row->github_score);
        // base=min(40, log10(100)*20)=40, activity=min(40, 8*5)=40, innovation=min(20, 5*4)=20 -> 100
        $this->assertSame(100, $row->github_score);
    }

    #[Test]
    public function isolates_404_and_continues_processing_other_companies(): void
    {
        $bad = Company::factory()->create([
            'is_active' => true,
            'github_org' => 'ghost-org',
        ]);
        $good = Company::factory()->create([
            'is_active' => true,
            'github_org' => 'acme',
        ]);

        Http::fake([
            'api.github.com/orgs/ghost-org' => Http::response(['message' => 'Not Found'], 404),
            'api.github.com/orgs/acme' => Http::response(['public_repos' => 10], 200),
            'api.github.com/orgs/acme/repos*' => Http::response($this->repoPayload(activeRepos: 2, newRepos: 1), 200),
        ]);

        Artisan::call('signals:github:poll', ['--tenant' => 'primary']);

        $today = CarbonImmutable::now('UTC')->toDateString();

        $goodRow = CompanySnapshot::query()
            ->where('company_id', $good->id)
            ->where('snapshot_date', $today)
            ->first();
        $this->assertNotNull($goodRow);
        $this->assertNotNull($goodRow->github_score);

        $badRow = CompanySnapshot::query()
            ->where('company_id', $bad->id)
            ->where('snapshot_date', $today)
            ->first();
        // Failed orgs must not produce a snapshot row from this command.
        $this->assertNull($badRow);
    }

    #[Test]
    public function score_computation_matches_formula_for_known_inputs(): void
    {
        // public_repos=10 -> base = log10(10)*20 = 20
        // active=2 -> activity = 10
        // new=1 -> innovation = 4
        // total = 34
        Http::fake([
            'api.github.com/orgs/acme' => Http::response(['public_repos' => 10], 200),
            'api.github.com/orgs/acme/repos*' => Http::response($this->repoPayload(activeRepos: 2, newRepos: 1), 200),
        ]);

        $scorer = $this->app->make(GithubOrgScorer::class);
        $result = $scorer->score('acme');

        $this->assertSame(34, $result['score']);
        $this->assertSame(10, $result['public_repos']);
        $this->assertSame(2, $result['pushed_repos_90d']);
        $this->assertSame(1, $result['new_repos_90d']);
    }

    #[Test]
    public function score_is_capped_at_100(): void
    {
        Http::fake([
            'api.github.com/orgs/big' => Http::response(['public_repos' => 100000], 200),
            'api.github.com/orgs/big/repos*' => Http::response($this->repoPayload(activeRepos: 10, newRepos: 10), 200),
        ]);

        $scorer = $this->app->make(GithubOrgScorer::class);
        $result = $scorer->score('big');

        $this->assertLessThanOrEqual(100, $result['score']);
        $this->assertSame(100, $result['score']);
    }

    #[Test]
    public function rerun_is_idempotent_and_updates_score_in_place(): void
    {
        $company = Company::factory()->create([
            'is_active' => true,
            'github_org' => 'acme',
        ]);

        $this->fakeOrg('acme', publicRepos: 10, activeRepos: 2, newRepos: 1);

        Artisan::call('signals:github:poll', ['--tenant' => 'primary']);
        Artisan::call('signals:github:poll', ['--tenant' => 'primary']);

        $today = CarbonImmutable::now('UTC')->toDateString();
        $rows = CompanySnapshot::query()
            ->where('company_id', $company->id)
            ->where('snapshot_date', $today)
            ->get();

        $this->assertCount(1, $rows);
        $this->assertSame(34, $rows->first()->github_score);
    }

    #[Test]
    public function skips_companies_without_github_org(): void
    {
        Company::factory()->create(['is_active' => true, 'github_org' => null]);

        Http::fake();

        Artisan::call('signals:github:poll', ['--tenant' => 'primary']);

        Http::assertNothingSent();
        $this->assertSame(0, CompanySnapshot::query()->count());
    }

    /**
     * Stub both org endpoints so the scorer produces a deterministic score.
     */
    private function fakeOrg(string $org, int $publicRepos, int $activeRepos, int $newRepos): void
    {
        Http::fake([
            "api.github.com/orgs/{$org}" => Http::response(['public_repos' => $publicRepos], 200),
            "api.github.com/orgs/{$org}/repos*" => Http::response(
                $this->repoPayload($activeRepos, $newRepos),
                200,
            ),
        ]);
    }

    /**
     * Build a 10-item repos page where the first $activeRepos have a recent
     * pushed_at and the first $newRepos have a recent created_at.
     *
     * @return array<int, array<string, string>>
     */
    private function repoPayload(int $activeRepos, int $newRepos): array
    {
        $now = CarbonImmutable::now('UTC');
        $recent = $now->subDays(10)->toIso8601String();
        $stale = $now->subDays(400)->toIso8601String();

        $repos = [];
        for ($i = 0; $i < 10; $i++) {
            $repos[] = [
                'name' => "repo-{$i}",
                'pushed_at' => $i < $activeRepos ? $recent : $stale,
                'created_at' => $i < $newRepos ? $recent : $stale,
            ];
        }

        return $repos;
    }
}
