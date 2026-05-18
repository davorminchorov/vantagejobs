<?php

declare(strict_types=1);

namespace Tests\Feature\Insights;

use App\Enums\EventType;
use App\Http\Controllers\Insights\ConfirmFundingCandidateController;
use App\Http\Controllers\Insights\DismissFundingCandidateController;
use App\Http\Controllers\Insights\FundingCandidatesController;
use App\Models\Company;
use App\Models\Event;
use App\Models\FundingCandidate;
use App\Models\User;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class FundingCandidatesTest extends TestCase
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

        // Routes will be added by the wiring patch — register inline for now
        // so the controllers can be exercised end-to-end.
        Route::middleware(['web', 'auth'])->group(function (): void {
            Route::get('/insights/funding-candidates', FundingCandidatesController::class)
                ->name('insights.funding-candidates.index');
            Route::post('/insights/funding-candidates/{candidate}/confirm', ConfirmFundingCandidateController::class)
                ->name('insights.funding-candidates.confirm');
            Route::post('/insights/funding-candidates/{candidate}/dismiss', DismissFundingCandidateController::class)
                ->name('insights.funding-candidates.dismiss');
        });
    }

    #[Test]
    public function index_lists_only_new_candidates(): void
    {
        $company = Company::factory()->create();
        $new = FundingCandidate::factory()->create([
            'company_id' => $company->id,
            'status' => FundingCandidate::STATUS_NEW,
            'title' => 'Acme raised $20M Series B',
        ]);
        FundingCandidate::factory()->create([
            'company_id' => $company->id,
            'status' => FundingCandidate::STATUS_CONFIRMED,
        ]);
        FundingCandidate::factory()->create([
            'company_id' => $company->id,
            'status' => FundingCandidate::STATUS_DISMISSED,
        ]);

        $response = $this->actingAs($this->user)->get('/insights/funding-candidates');
        $response->assertOk();

        $candidates = $response->viewData('page')['props']['candidates'];
        $this->assertCount(1, $candidates);
        $this->assertSame($new->id, $candidates[0]['id']);
        $this->assertSame('Acme raised $20M Series B', $candidates[0]['title']);
        $this->assertSame($company->name, $candidates[0]['company']['name']);
    }

    #[Test]
    public function confirm_flips_status_and_writes_funding_event(): void
    {
        $company = Company::factory()->create();
        $candidate = FundingCandidate::factory()->create([
            'company_id' => $company->id,
            'status' => FundingCandidate::STATUS_NEW,
            'title' => 'Acme raised $20M Series B',
            'url' => 'https://news.example.com/x',
            'matched_keywords' => ['raised', 'Series B'],
        ]);

        $this->actingAs($this->user)
            ->post("/insights/funding-candidates/{$candidate->id}/confirm")
            ->assertRedirect();

        $this->assertSame(
            FundingCandidate::STATUS_CONFIRMED,
            FundingCandidate::query()->find($candidate->id)?->status,
        );

        $event = Event::query()
            ->where('company_id', $company->id)
            ->where('type', EventType::FUNDING->value)
            ->first();
        $this->assertNotNull($event);
        $this->assertSame('Acme raised $20M Series B', $event->title);

        $body = json_decode((string) $event->body, true);
        $this->assertSame('google_news', $body['source']);
        $this->assertSame($candidate->id, $body['candidate_id']);
    }

    #[Test]
    public function dismiss_flips_status_without_creating_event(): void
    {
        $company = Company::factory()->create();
        $candidate = FundingCandidate::factory()->create([
            'company_id' => $company->id,
            'status' => FundingCandidate::STATUS_NEW,
        ]);

        $this->actingAs($this->user)
            ->post("/insights/funding-candidates/{$candidate->id}/dismiss")
            ->assertRedirect();

        $this->assertSame(
            FundingCandidate::STATUS_DISMISSED,
            FundingCandidate::query()->find($candidate->id)?->status,
        );

        $this->assertSame(
            0,
            Event::query()->where('type', EventType::FUNDING->value)->count(),
        );
    }
}
