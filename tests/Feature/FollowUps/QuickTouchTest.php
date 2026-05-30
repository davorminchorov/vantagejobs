<?php

declare(strict_types=1);

namespace Tests\Feature\FollowUps;

use App\Enums\OutreachChannel;
use App\Models\Company;
use App\Models\Contact;
use App\Models\OutreachLogEntry;
use App\Models\User;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class QuickTouchTest extends TestCase
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
    public function unauthenticated_users_are_redirected_to_login(): void
    {
        $contact = Contact::factory()->create();

        $this->post("/follow-ups/{$contact->id}/touch")
            ->assertRedirect('/login');

        $this->assertSame(0, OutreachLogEntry::query()->count());
    }

    #[Test]
    public function logs_a_touch_and_advances_last_touch(): void
    {
        $contact = Contact::factory()
            ->touchedDaysAgo(30)
            ->create();

        $this->actingAs($this->user)
            ->post("/follow-ups/{$contact->id}/touch")
            ->assertRedirect('/follow-ups');

        $entry = OutreachLogEntry::query()->where('contact_id', $contact->id)->first();
        $this->assertNotNull($entry);
        $this->assertSame(OutreachChannel::OTHER, $entry->channel);
        $this->assertNull($entry->subject);
        $this->assertNull($entry->body);

        $contact->refresh();
        $this->assertNotNull($contact->last_touch);
        $this->assertTrue($contact->last_touch->greaterThan(CarbonImmutable::now()->subMinute()));
    }

    #[Test]
    public function accepts_an_optional_channel_and_note(): void
    {
        $contact = Contact::factory()->create(['last_touch' => null]);

        $this->actingAs($this->user)
            ->post("/follow-ups/{$contact->id}/touch", [
                'channel' => OutreachChannel::EMAIL->value,
                'note' => 'Sent a check-in email.',
            ])
            ->assertRedirect('/follow-ups');

        $entry = OutreachLogEntry::query()->where('contact_id', $contact->id)->first();
        $this->assertNotNull($entry);
        $this->assertSame(OutreachChannel::EMAIL, $entry->channel);
        $this->assertSame('Sent a check-in email.', $entry->body);
    }

    #[Test]
    public function rejects_an_invalid_channel(): void
    {
        $contact = Contact::factory()->create();

        $this->actingAs($this->user)
            ->from('/follow-ups')
            ->post("/follow-ups/{$contact->id}/touch", ['channel' => 'carrier-pigeon'])
            ->assertSessionHasErrors('channel');

        $this->assertSame(0, OutreachLogEntry::query()->count());
    }

    #[Test]
    public function rejects_an_overlong_note(): void
    {
        $contact = Contact::factory()->create();

        $this->actingAs($this->user)
            ->from('/follow-ups')
            ->post("/follow-ups/{$contact->id}/touch", ['note' => str_repeat('a', 2001)])
            ->assertSessionHasErrors('note');

        $this->assertSame(0, OutreachLogEntry::query()->count());
    }

    #[Test]
    public function does_not_pull_last_touch_backwards(): void
    {
        $recent = CarbonImmutable::now()->subDay();
        $contact = Contact::factory()->create(['last_touch' => $recent]);

        $this->actingAs($this->user)
            ->post("/follow-ups/{$contact->id}/touch");

        $contact->refresh();
        // A "now" touch is more recent than yesterday, so last_touch advances.
        $this->assertTrue($contact->last_touch->greaterThan($recent));
    }

    #[Test]
    public function cannot_touch_a_contact_in_another_tenant(): void
    {
        $otherTenant = Tenant::factory()->create();
        $foreignContact = app(TenantContext::class)->withTenant(
            $otherTenant,
            fn (): Contact => Contact::factory()->create(),
        );

        $this->actingAs($this->user)
            ->post("/follow-ups/{$foreignContact->id}/touch")
            ->assertNotFound();

        $this->assertSame(0, OutreachLogEntry::withoutTenantScope()->count());
    }

    #[Test]
    public function the_outreach_entry_is_owned_by_the_current_tenant(): void
    {
        $company = Company::factory()->create();
        $contact = Contact::factory()->create(['company_id' => $company->id]);

        $this->actingAs($this->user)
            ->post("/follow-ups/{$contact->id}/touch");

        $entry = OutreachLogEntry::query()->where('contact_id', $contact->id)->first();
        $this->assertNotNull($entry);
        $this->assertSame($this->tenant->id, (int) $entry->getAttribute('tenant_id'));
    }
}
