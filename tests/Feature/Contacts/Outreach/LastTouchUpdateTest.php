<?php

declare(strict_types=1);

namespace Tests\Feature\Contacts\Outreach;

use App\Enums\OutreachChannel;
use App\Models\Contact;
use App\Models\OutreachLogEntry;
use App\Models\User;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class LastTouchUpdateTest extends TestCase
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
    public function logging_outreach_advances_last_touch_to_occurred_at(): void
    {
        $contact = Contact::factory()->create(['last_touch' => null]);

        $this->actingAs($this->user)
            ->post("/contacts/{$contact->id}/outreach", [
                'channel' => OutreachChannel::EMAIL->value,
                'occurred_at' => '2026-05-25',
            ])
            ->assertRedirect("/contacts/{$contact->id}");

        $contact->refresh();
        $this->assertNotNull($contact->last_touch);
        $this->assertSame('2026-05-25', $contact->last_touch->toDateString());
    }

    #[Test]
    public function last_touch_defaults_to_now_when_occurred_at_is_omitted(): void
    {
        $contact = Contact::factory()->create(['last_touch' => null]);

        $this->actingAs($this->user)
            ->post("/contacts/{$contact->id}/outreach", [
                'channel' => OutreachChannel::PHONE->value,
            ])
            ->assertRedirect("/contacts/{$contact->id}");

        $contact->refresh();
        $this->assertNotNull($contact->last_touch);
        $this->assertTrue($contact->last_touch->isToday());
    }

    #[Test]
    public function a_more_recent_entry_advances_last_touch_forward(): void
    {
        $contact = Contact::factory()->create([
            'last_touch' => CarbonImmutable::parse('2026-05-01'),
        ]);

        $this->actingAs($this->user)
            ->post("/contacts/{$contact->id}/outreach", [
                'channel' => OutreachChannel::EMAIL->value,
                'occurred_at' => '2026-05-28',
            ])
            ->assertRedirect("/contacts/{$contact->id}");

        $contact->refresh();
        $this->assertSame('2026-05-28', $contact->last_touch?->toDateString());
    }

    #[Test]
    public function a_back_dated_entry_does_not_pull_last_touch_backwards(): void
    {
        $contact = Contact::factory()->create([
            'last_touch' => CarbonImmutable::parse('2026-05-20'),
        ]);

        $this->actingAs($this->user)
            ->post("/contacts/{$contact->id}/outreach", [
                'channel' => OutreachChannel::EMAIL->value,
                'occurred_at' => '2026-05-01',
            ])
            ->assertRedirect("/contacts/{$contact->id}");

        // The entry is still recorded for history, but last_touch holds at the
        // more recent existing value.
        $this->assertDatabaseHas('outreach_log_entries', [
            'contact_id' => $contact->id,
        ]);

        $contact->refresh();
        $this->assertSame('2026-05-20', $contact->last_touch?->toDateString());
    }

    #[Test]
    public function entry_and_last_touch_are_written_in_one_transaction(): void
    {
        $contact = Contact::factory()->create(['last_touch' => null]);

        $this->actingAs($this->user)
            ->post("/contacts/{$contact->id}/outreach", [
                'channel' => OutreachChannel::LINKEDIN->value,
                'occurred_at' => '2026-05-15',
            ])
            ->assertRedirect("/contacts/{$contact->id}");

        $entry = OutreachLogEntry::query()->where('contact_id', $contact->id)->firstOrFail();
        $contact->refresh();

        $this->assertSame(
            $entry->occurred_at?->toDateString(),
            $contact->last_touch?->toDateString(),
        );
    }
}
