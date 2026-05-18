<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\AggregatorSourceType;
use App\Models\AggregatorListing;
use App\Models\AggregatorSource;
use App\Models\ParseCorrection;
use App\Models\ParserRun;
use App\Models\User;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ParserReparseAllTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    private AggregatorSource $remotive;

    private AggregatorSource $hackernews;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['slug' => 'primary']);
        app(TenantContext::class)->setCurrent($this->tenant);
        $this->user = User::factory()->create();
        $this->remotive = AggregatorSource::factory()->ofType(AggregatorSourceType::REMOTIVE)->create();
        $this->hackernews = AggregatorSource::factory()->ofType(AggregatorSourceType::HACKERNEWS)->create();
    }

    /**
     * @return array<string, mixed>
     */
    private function remotiveRaw(string $title, string $company): array
    {
        return [
            'id' => fake()->unique()->numberBetween(1, 999999),
            'url' => 'https://remotive.com/jobs/abc',
            'title' => $title,
            'company_name' => $company,
            'salary' => '$150,000 - $200,000',
            'job_type' => 'full_time',
            'candidate_required_location' => 'Worldwide',
            'tags' => ['python'],
            'publication_date' => '2026-05-01T12:00:00',
            'description' => '<p>'.str_repeat('We are hiring. ', 20).'</p>',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function hackernewsRaw(string $title): array
    {
        return [
            'id' => fake()->unique()->numberBetween(1, 999999),
            'author' => 'someuser',
            'text' => 'Acme Corp | '.$title.' | REMOTE | $150,000 - $200,000<p>'.str_repeat('Hello there. ', 20).'</p>',
            'created_at' => '2026-05-01T12:00:00Z',
        ];
    }

    #[Test]
    public function reparse_all_updates_parsed_and_increments_parser_version(): void
    {
        $listing = AggregatorListing::factory()->create([
            'source_id' => $this->remotive->id,
            'raw' => $this->remotiveRaw('Senior Backend Engineer', 'Stripe'),
            'parsed' => [],
            'parsed_company_name' => null,
            'parsed_role_title' => null,
            'parser_version' => 1,
        ]);

        $exit = Artisan::call('parser:reparse-all', ['--tenant' => 'primary']);
        $this->assertSame(0, $exit);

        $fresh = $listing->fresh();
        $this->assertSame(2, $fresh->parser_version);
        $this->assertSame('Stripe', $fresh->parsed_company_name);
        $this->assertSame('Senior Backend Engineer', $fresh->parsed_role_title);
        $this->assertIsArray($fresh->parsed);
        $this->assertArrayHasKey('company_name', $fresh->parsed);

        $run = ParserRun::query()->sole();
        $this->assertSame(2, $run->target_version);
        $this->assertSame(1, $run->processed);
        $this->assertNotNull($run->finished_at);
        $this->assertSame((int) $listing->id, (int) $run->to_id);
        $this->assertFalse($run->force_corrections);
    }

    #[Test]
    public function corrections_are_preserved_by_default(): void
    {
        $listing = AggregatorListing::factory()->create([
            'source_id' => $this->remotive->id,
            'raw' => $this->remotiveRaw('Senior Backend Engineer', 'Stripe'),
            'parsed' => [],
            'parsed_role_title' => 'Senior Backend Engineer',
            'parser_version' => 1,
        ]);

        ParseCorrection::query()->create([
            'tenant_id' => $this->tenant->id,
            'listing_id' => $listing->id,
            'user_id' => $this->user->id,
            'field' => 'role_title',
            'old_value' => ['value' => 'Senior Backend Engineer'],
            'new_value' => ['value' => 'Staff Backend Engineer'],
        ]);

        Artisan::call('parser:reparse-all', ['--tenant' => 'primary']);

        $fresh = $listing->fresh();
        $this->assertSame('Staff Backend Engineer', $fresh->parsed_role_title);
        $slot = $fresh->parsedField('role_title');
        $this->assertSame('explicit', $slot['source']);
        $this->assertSame(1, ParseCorrection::query()->where('listing_id', $listing->id)->count());
    }

    #[Test]
    public function force_corrections_drops_the_overlay(): void
    {
        $listing = AggregatorListing::factory()->create([
            'source_id' => $this->remotive->id,
            'raw' => $this->remotiveRaw('Senior Backend Engineer', 'Stripe'),
            'parsed' => [],
            'parsed_role_title' => 'Senior Backend Engineer',
            'parser_version' => 1,
        ]);

        ParseCorrection::query()->create([
            'tenant_id' => $this->tenant->id,
            'listing_id' => $listing->id,
            'user_id' => $this->user->id,
            'field' => 'role_title',
            'old_value' => ['value' => 'Senior Backend Engineer'],
            'new_value' => ['value' => 'Staff Backend Engineer'],
        ]);

        Artisan::call('parser:reparse-all', ['--tenant' => 'primary', '--force-corrections' => true]);

        $fresh = $listing->fresh();
        $this->assertSame('Senior Backend Engineer', $fresh->parsed_role_title);
        // The audit row stays — only the live state changes.
        $this->assertSame(1, ParseCorrection::query()->where('listing_id', $listing->id)->count());
    }

    #[Test]
    public function reparse_is_resumable_via_from_flag(): void
    {
        $listings = [];
        for ($i = 0; $i < 5; $i++) {
            $listings[] = AggregatorListing::factory()->create([
                'source_id' => $this->remotive->id,
                'raw' => $this->remotiveRaw("Role {$i}", "Co {$i}"),
                'parsed' => [],
                'parser_version' => 1,
            ]);
        }

        // Manually pre-bump the first three to simulate "processed before crash".
        $targetVersion = 2;
        foreach (array_slice($listings, 0, 3) as $listing) {
            $listing->parser_version = $targetVersion;
            $listing->save();
        }

        $resumeFromId = $listings[3]->id;

        Artisan::call('parser:reparse-all', [
            '--tenant' => 'primary',
            '--from' => (string) $resumeFromId,
        ]);

        // Resume mode: target = max(2). The pre-bumped rows are already at 2
        // and stay; the remaining rows bump from 1 to 2.
        foreach ($listings as $idx => $listing) {
            $this->assertSame(2, $listing->fresh()->parser_version, "Listing #{$idx} version mismatch");
        }

        $run = ParserRun::query()->sole();
        $this->assertSame(2, (int) $run->target_version);
        $this->assertSame(2, (int) $run->processed);
    }

    #[Test]
    public function rerunning_back_to_back_is_safe_and_lands_on_a_stable_state(): void
    {
        $listing = AggregatorListing::factory()->create([
            'source_id' => $this->remotive->id,
            'raw' => $this->remotiveRaw('Role', 'Co'),
            'parsed' => [],
            'parser_version' => 1,
        ]);

        Artisan::call('parser:reparse-all', ['--tenant' => 'primary']);
        $afterFirst = $listing->fresh()->parsed;

        Artisan::call('parser:reparse-all', ['--tenant' => 'primary']);
        $afterSecond = $listing->fresh()->parsed;

        // Same parsed output across the two runs (the parser is deterministic
        // on the same raw record).
        $this->assertSame($afterFirst, $afterSecond);

        $runs = ParserRun::query()->orderBy('id')->get();
        $this->assertCount(2, $runs);
        $this->assertGreaterThan($runs[0]->target_version, $runs[1]->target_version);
    }

    #[Test]
    public function source_filter_only_touches_matching_listings(): void
    {
        $remotiveListing = AggregatorListing::factory()->create([
            'source_id' => $this->remotive->id,
            'raw' => $this->remotiveRaw('Role A', 'Co A'),
            'parsed' => [],
            'parser_version' => 1,
        ]);

        $hnListing = AggregatorListing::factory()->create([
            'source_id' => $this->hackernews->id,
            'raw' => $this->hackernewsRaw('Senior Engineer'),
            'parsed' => [],
            'parser_version' => 1,
        ]);

        Artisan::call('parser:reparse-all', [
            '--tenant' => 'primary',
            '--source' => 'hackernews',
        ]);

        $this->assertSame(1, $remotiveListing->fresh()->parser_version);
        $this->assertSame(2, $hnListing->fresh()->parser_version);

        $run = ParserRun::query()->sole();
        $this->assertSame('hackernews', $run->source_filter);
    }

    #[Test]
    public function chunk_is_one_transaction_not_per_row(): void
    {
        for ($i = 0; $i < 5; $i++) {
            AggregatorListing::factory()->create([
                'source_id' => $this->remotive->id,
                'raw' => $this->remotiveRaw("Role {$i}", "Co {$i}"),
                'parsed' => [],
                'parser_version' => 1,
            ]);
        }

        $commits = 0;
        DB::listen(static function ($query) use (&$commits): void {
            $sql = strtolower($query->sql);
            if ($sql === 'commit') {
                $commits++;
            }
        });

        Artisan::call('parser:reparse-all', [
            '--tenant' => 'primary',
            '--chunk' => '5',
        ]);

        // Exactly one commit for the data chunk + one for the ParserRun open + a few for fills.
        // The key assertion is that we never get a commit per row (would be >=5).
        $this->assertLessThan(5, $commits, "Expected fewer commits than rows; got {$commits}");
    }

    #[Test]
    public function parser_reparse_alias_requires_source(): void
    {
        $exit = Artisan::call('parser:reparse', ['--tenant' => 'primary']);
        $this->assertSame(1, $exit);
    }

    #[Test]
    public function parser_reparse_alias_forwards_to_reparse_all(): void
    {
        $listing = AggregatorListing::factory()->create([
            'source_id' => $this->remotive->id,
            'raw' => $this->remotiveRaw('Role', 'Co'),
            'parsed' => [],
            'parser_version' => 1,
        ]);

        $exit = Artisan::call('parser:reparse', [
            '--tenant' => 'primary',
            '--source' => 'remotive',
        ]);

        $this->assertSame(0, $exit);
        $this->assertSame(2, $listing->fresh()->parser_version);
    }
}
