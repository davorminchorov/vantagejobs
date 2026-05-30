<?php

declare(strict_types=1);

namespace Tests\Feature\Calendar;

use App\Calendar\CalendarFeedCacheKey;
use App\Models\CalendarEvent;
use App\Models\User;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class IcalFeedCacheTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['slug' => 'ical-cache-test']);
        app(TenantContext::class)->setCurrent($this->tenant);

        $this->token = Str::random(40);
        User::factory()->create(['calendar_feed_token' => $this->token]);
    }

    #[Test]
    public function the_second_fetch_is_served_from_cache(): void
    {
        CalendarEvent::factory()->create([
            'title' => 'First event',
            'starts_at' => CarbonImmutable::now('UTC')->addDay(),
        ]);

        $first = (string) $this->get("/calendar/{$this->token}/feed.ics")->getContent();
        $this->assertStringContainsString('First event', $first);

        // A new event created after the cache is warm must NOT appear until the
        // cache entry expires or is explicitly forgotten.
        CalendarEvent::factory()->create([
            'title' => 'Sneaky second event',
            'starts_at' => CarbonImmutable::now('UTC')->addDay(),
        ]);

        $second = (string) $this->get("/calendar/{$this->token}/feed.ics")->getContent();
        $this->assertStringNotContainsString('Sneaky second event', $second);
        $this->assertSame($first, $second);
    }

    #[Test]
    public function forgetting_the_cache_key_surfaces_new_events(): void
    {
        CalendarEvent::factory()->create([
            'title' => 'Original event',
            'starts_at' => CarbonImmutable::now('UTC')->addDay(),
        ]);

        $this->get("/calendar/{$this->token}/feed.ics")->assertOk();

        CalendarEvent::factory()->create([
            'title' => 'Newly visible event',
            'starts_at' => CarbonImmutable::now('UTC')->addDay(),
        ]);

        // Simulate a calendar write controller invalidating the feed.
        app(CacheRepository::class)->forget(CalendarFeedCacheKey::forToken($this->token));

        $body = (string) $this->get("/calendar/{$this->token}/feed.ics")->getContent();
        $this->assertStringContainsString('Newly visible event', $body);
    }

    #[Test]
    public function the_feed_body_is_stored_under_the_token_keyed_cache_entry(): void
    {
        CalendarEvent::factory()->create([
            'starts_at' => CarbonImmutable::now('UTC')->addDay(),
        ]);

        $cache = app(CacheRepository::class);
        $key = CalendarFeedCacheKey::forToken($this->token);

        $this->assertNull($cache->get($key));

        $this->get("/calendar/{$this->token}/feed.ics")->assertOk();

        $this->assertNotNull($cache->get($key));
        $this->assertStringContainsString('BEGIN:VCALENDAR', (string) $cache->get($key));
    }
}
