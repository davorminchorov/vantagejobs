<?php

declare(strict_types=1);

namespace Tests\Feature\Calendar;

use App\Models\CalendarEvent;
use App\Models\User;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class IcalFeedTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['slug' => 'ical-feed-test']);
        app(TenantContext::class)->setCurrent($this->tenant);

        $this->token = Str::random(40);
        $this->user = User::factory()->create([
            'name' => 'Ada Lovelace',
            'calendar_feed_token' => $this->token,
        ]);
    }

    #[Test]
    public function it_returns_a_valid_vcalendar_with_a_text_calendar_content_type(): void
    {
        CalendarEvent::factory()->create([
            'title' => 'Onsite interview',
            'starts_at' => CarbonImmutable::now('UTC')->addDays(3),
            'timezone' => 'America/New_York',
        ]);

        $response = $this->get("/calendar/{$this->token}/feed.ics");

        $response->assertOk();
        $this->assertStringStartsWith('text/calendar', (string) $response->headers->get('Content-Type'));

        $body = $response->getContent();
        $this->assertStringContainsString('BEGIN:VCALENDAR', $body);
        $this->assertStringContainsString('VERSION:2.0', $body);
        $this->assertStringContainsString('END:VCALENDAR', $body);
        $this->assertStringContainsString('BEGIN:VEVENT', $body);
        $this->assertStringContainsString('SUMMARY:Onsite interview', $body);
        $this->assertStringContainsString("\r\n", $body);
    }

    #[Test]
    public function each_event_carries_a_stable_tenant_scoped_uid(): void
    {
        $event = CalendarEvent::factory()->create([
            'starts_at' => CarbonImmutable::now('UTC')->addDay(),
        ]);

        $body = (string) $this->get("/calendar/{$this->token}/feed.ics")->getContent();

        $this->assertStringContainsString("UID:vantagejobs-{$this->tenant->id}-{$event->id}", $body);
    }

    #[Test]
    public function dtstart_is_emitted_in_utc_and_a_vtimezone_is_present_for_original_zones(): void
    {
        $startsAt = CarbonImmutable::now('UTC')->addDays(2)->setTime(14, 30, 0);

        CalendarEvent::factory()->create([
            'starts_at' => $startsAt,
            'timezone' => 'America/New_York',
        ]);

        $body = (string) $this->get("/calendar/{$this->token}/feed.ics")->getContent();

        $this->assertStringContainsString('DTSTART:'.$startsAt->format('Ymd\THis\Z'), $body);
        $this->assertStringContainsString('BEGIN:VTIMEZONE', $body);
        $this->assertStringContainsString('TZID:America/New_York', $body);
    }

    #[Test]
    public function it_excludes_past_events(): void
    {
        CalendarEvent::factory()->create([
            'title' => 'Past standup',
            'starts_at' => CarbonImmutable::now('UTC')->subDay(),
        ]);
        CalendarEvent::factory()->create([
            'title' => 'Future kickoff',
            'starts_at' => CarbonImmutable::now('UTC')->addDay(),
        ]);

        $body = (string) $this->get("/calendar/{$this->token}/feed.ics")->getContent();

        $this->assertStringNotContainsString('Past standup', $body);
        $this->assertStringContainsString('Future kickoff', $body);
    }

    #[Test]
    public function it_only_includes_events_for_the_token_owners_tenant(): void
    {
        CalendarEvent::factory()->create([
            'title' => 'Mine',
            'starts_at' => CarbonImmutable::now('UTC')->addDay(),
        ]);

        $otherTenant = Tenant::factory()->create(['slug' => 'other-ical-tenant']);
        app(TenantContext::class)->withTenant($otherTenant, function (): void {
            CalendarEvent::factory()->create([
                'title' => 'Theirs',
                'starts_at' => CarbonImmutable::now('UTC')->addDay(),
            ]);
        });

        $body = (string) $this->get("/calendar/{$this->token}/feed.ics")->getContent();

        $this->assertStringContainsString('Mine', $body);
        $this->assertStringNotContainsString('Theirs', $body);
    }

    #[Test]
    public function an_unknown_token_returns_404(): void
    {
        $this->get('/calendar/not-a-real-token/feed.ics')->assertNotFound();
    }

    #[Test]
    public function the_feed_is_public_and_requires_no_authentication(): void
    {
        CalendarEvent::factory()->create([
            'starts_at' => CarbonImmutable::now('UTC')->addDay(),
        ]);

        // No actingAs — anyone with the token URL can read it.
        $this->get("/calendar/{$this->token}/feed.ics")->assertOk();
    }

    #[Test]
    public function it_escapes_special_characters_in_summaries(): void
    {
        CalendarEvent::factory()->create([
            'title' => 'Chat; coffee, notes',
            'starts_at' => CarbonImmutable::now('UTC')->addDay(),
        ]);

        $body = (string) $this->get("/calendar/{$this->token}/feed.ics")->getContent();

        $this->assertStringContainsString('SUMMARY:Chat\\; coffee\\, notes', $body);
    }
}
