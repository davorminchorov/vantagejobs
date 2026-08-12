<?php

declare(strict_types=1);

namespace Tests\Feature\Scrapers\Aggregator;

use App\Enums\AggregatorSourceType;
use App\Models\AggregatorListing;
use App\Models\AggregatorSource;
use App\Scrapers\Aggregator\AggregatorListingPersister;
use App\Scrapers\Aggregator\Parser\SourceContext;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Item 10: hardens AggregatorListingPersister::upsert() against the
 * check-then-insert race between two sources ingesting the same role at
 * once. Covers only the lock's acquire/skip decision — the dedup outcome
 * itself is covered by DedupAndVolumeCapTest.
 */
final class DedupLockTest extends TestCase
{
    use RefreshDatabase;

    private AggregatorSource $source;

    private AggregatorListingPersister $persister;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::factory()->create();
        app(TenantContext::class)->setCurrent($tenant);

        $this->source = AggregatorSource::factory()->ofType(AggregatorSourceType::HACKERNEWS)->create();
        $this->persister = $this->app->make(AggregatorListingPersister::class);
    }

    #[Test]
    public function acquires_a_dedup_lock_when_the_role_hash_is_complete(): void
    {
        $capturedKey = null;
        $capturedTtl = null;

        $lock = Mockery::mock(Lock::class);
        $lock->shouldReceive('block')
            ->once()
            ->with(2, Mockery::type('callable'))
            ->andReturnUsing(fn (int $seconds, callable $callback) => $callback());

        Cache::shouldReceive('lock')
            ->once()
            ->withArgs(function (string $key, int $ttl) use (&$capturedKey, &$capturedTtl): bool {
                $capturedKey = $key;
                $capturedTtl = $ttl;

                return true;
            })
            ->andReturn($lock);

        $id = $this->upsert('hn-locked', 'Acme Corp', 'Senior Backend Engineer');

        $this->assertNotNull($id);
        $this->assertSame(1, AggregatorListing::query()->count());
        $this->assertSame(5, $capturedTtl);
        $this->assertStringStartsWith('listing-dedup:'.$this->source->tenant_id.':', (string) $capturedKey);
    }

    #[Test]
    public function skips_the_lock_when_dedup_keys_are_incomplete(): void
    {
        Cache::shouldReceive('lock')->never();

        // No company name -> normalized_company_key is null -> incomplete
        // role_hash -> applyDedupGate() already skips these rows, so the
        // lock has nothing to protect.
        $id = $this->upsert('hn-no-company', null, 'Senior Backend Engineer');

        $this->assertNotNull($id);
        $this->assertSame(1, AggregatorListing::query()->count());
    }

    #[Test]
    public function falls_back_to_creating_without_the_lock_on_timeout(): void
    {
        Cache::shouldReceive('lock')
            ->once()
            ->andThrow(new LockTimeoutException());

        $id = $this->upsert('hn-timeout', 'Acme Corp', 'Senior Backend Engineer');

        $this->assertNotNull($id);
        $this->assertSame(1, AggregatorListing::query()->count());
    }

    private function upsert(string $externalId, ?string $companyName, string $roleTitle): ?int
    {
        $structured = [
            'title' => $roleTitle,
            'location' => 'Worldwide',
        ];
        if ($companyName !== null) {
            $structured['company_name'] = $companyName;
        }

        $context = new SourceContext(
            sourceType: $this->source->type,
            structured: $structured,
            externalId: $externalId,
        );

        return $this->persister->upsert($this->source, $context, $structured, $externalId);
    }
}
