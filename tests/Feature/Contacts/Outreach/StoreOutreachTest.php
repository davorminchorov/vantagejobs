<?php

declare(strict_types=1);

namespace Tests\Feature\Contacts\Outreach;

use App\Enums\OutreachChannel;
use App\Models\Contact;
use App\Models\OutreachLogEntry;
use App\Models\User;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class StoreOutreachTest extends TestCase
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
    public function it_appends_an_outreach_entry_to_the_contact(): void
    {
        $contact = Contact::factory()->create();

        $this->actingAs($this->user)
            ->post("/contacts/{$contact->id}/outreach", [
                'channel' => OutreachChannel::EMAIL->value,
                'subject' => 'Quick intro',
                'body' => 'Said hi about the staff role.',
                'occurred_at' => '2026-05-20',
            ])
            ->assertRedirect("/contacts/{$contact->id}");

        $this->assertDatabaseHas('outreach_log_entries', [
            'contact_id' => $contact->id,
            'tenant_id' => $this->tenant->id,
            'channel' => OutreachChannel::EMAIL->value,
            'subject' => 'Quick intro',
            'body' => 'Said hi about the staff role.',
        ]);

        $entry = OutreachLogEntry::query()->where('contact_id', $contact->id)->firstOrFail();
        $this->assertSame('2026-05-20', $entry->occurred_at?->toDateString());
    }

    #[Test]
    public function occurred_at_defaults_to_now_when_omitted(): void
    {
        $contact = Contact::factory()->create();

        $this->actingAs($this->user)
            ->post("/contacts/{$contact->id}/outreach", [
                'channel' => OutreachChannel::LINKEDIN->value,
            ])
            ->assertRedirect("/contacts/{$contact->id}");

        $entry = OutreachLogEntry::query()->where('contact_id', $contact->id)->firstOrFail();
        $this->assertNotNull($entry->occurred_at);
        $this->assertTrue($entry->occurred_at->isToday());
    }

    #[Test]
    public function subject_and_body_are_optional(): void
    {
        $contact = Contact::factory()->create();

        $this->actingAs($this->user)
            ->post("/contacts/{$contact->id}/outreach", [
                'channel' => OutreachChannel::PHONE->value,
            ])
            ->assertRedirect("/contacts/{$contact->id}");

        $this->assertDatabaseHas('outreach_log_entries', [
            'contact_id' => $contact->id,
            'channel' => OutreachChannel::PHONE->value,
            'subject' => null,
            'body' => null,
        ]);
    }

    #[Test]
    public function channel_is_required(): void
    {
        $contact = Contact::factory()->create();

        $this->actingAs($this->user)
            ->post("/contacts/{$contact->id}/outreach", [
                'subject' => 'No channel here',
            ])
            ->assertSessionHasErrors('channel');

        $this->assertDatabaseCount('outreach_log_entries', 0);
    }

    #[Test]
    public function channel_must_be_a_valid_enum_value(): void
    {
        $contact = Contact::factory()->create();

        $this->actingAs($this->user)
            ->post("/contacts/{$contact->id}/outreach", [
                'channel' => 'carrier-pigeon',
            ])
            ->assertSessionHasErrors('channel');

        $this->assertDatabaseCount('outreach_log_entries', 0);
    }

    #[Test]
    public function occurred_at_must_be_a_valid_date(): void
    {
        $contact = Contact::factory()->create();

        $this->actingAs($this->user)
            ->post("/contacts/{$contact->id}/outreach", [
                'channel' => OutreachChannel::EMAIL->value,
                'occurred_at' => 'not-a-date',
            ])
            ->assertSessionHasErrors('occurred_at');

        $this->assertDatabaseCount('outreach_log_entries', 0);
    }

    #[Test]
    public function guests_are_redirected_to_login(): void
    {
        $contact = Contact::factory()->create();

        $this->post("/contacts/{$contact->id}/outreach", [
            'channel' => OutreachChannel::EMAIL->value,
        ])->assertRedirect('/login');

        $this->assertDatabaseCount('outreach_log_entries', 0);
    }

    #[Test]
    public function unknown_contact_returns_404(): void
    {
        $this->actingAs($this->user)
            ->post('/contacts/999999/outreach', [
                'channel' => OutreachChannel::EMAIL->value,
            ])
            ->assertNotFound();

        $this->assertDatabaseCount('outreach_log_entries', 0);
    }

    #[Test]
    public function it_cannot_log_outreach_to_a_contact_from_another_tenant(): void
    {
        $otherTenant = Tenant::factory()->create();
        app(TenantContext::class)->setCurrent($otherTenant);
        $foreignContact = Contact::factory()->create(['name' => 'Foreign Person']);

        app(TenantContext::class)->setCurrent($this->tenant);

        $this->actingAs($this->user)
            ->post("/contacts/{$foreignContact->id}/outreach", [
                'channel' => OutreachChannel::EMAIL->value,
            ])
            ->assertNotFound();

        $this->assertDatabaseCount('outreach_log_entries', 0);
    }
}
