<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AggregatorListingStatus;
use App\Enums\PipelineStatus;
use App\FollowUps\DueFollowUpsQuery;
use App\Models\AggregatorListing;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Role;
use App\Scrapers\Aggregator\ActiveAutoDismissRulesQuery;
use App\Sources\SourceHealthQuery;
use Carbon\CarbonImmutable;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Renders the action-first dashboard: source health, follow-ups due, a
 * pipeline stage tally, the count of new Discover listings, and a slim
 * watch-list table. The follow-up count/top-3 reuse DueFollowUpsQuery, the
 * same definition FollowUps\IndexController uses; the pipeline stage tally
 * duplicates Pipeline\IndexController's stage set (RESEARCHING through
 * WITHDRAWN) rather than sharing a query object, since that controller has
 * no extractable query class to reuse and returns a full board payload we
 * don't need here.
 */
final readonly class DashboardController
{
    public function __construct(
        private ActiveAutoDismissRulesQuery $autoDismissRules,
        private SourceHealthQuery $sourceHealth,
        private DueFollowUpsQuery $dueFollowUps,
    ) {}

    public function __invoke(): Response
    {
        $companies = Company::query()
            ->select(['id', 'name', 'tier', 'status', 'last_checked_at'])
            ->withCount(['roles as open_roles' => fn ($q) => $q->where('is_open', true)])
            ->orderBy('tier')
            ->orderBy('name')
            ->get();

        return Inertia::render('Dashboard', [
            'companies' => $companies
                ->map(static fn (Company $company): array => [
                    'id' => $company->id,
                    'name' => $company->name,
                    'tier' => $company->tier,
                    'status' => $company->status,
                    'open_roles' => (int) $company->getAttribute('open_roles'),
                    'last_checked_at' => $company->last_checked_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
            'companies_count' => $companies->count(),
            'active_auto_dismiss_rules' => $this->autoDismissRules->active(),
            'source_health' => $this->sourceHealth->summary(),
            'follow_ups_due' => $this->followUpsDue(),
            'pipeline_summary' => $this->pipelineSummary(),
            'discover_new_count' => AggregatorListing::query()
                ->where('status', AggregatorListingStatus::NEW->value)
                ->count(),
        ]);
    }

    /**
     * @return array{
     *     count: int,
     *     top: list<array{contact_name: string, company_name: string|null, days_overdue: int|null}>
     * }
     */
    private function followUpsDue(): array
    {
        $now = CarbonImmutable::now();

        $top = $this->dueFollowUps->get(3)
            ->map(static fn (Contact $contact): array => [
                'contact_name' => $contact->name,
                'company_name' => $contact->company?->name,
                'days_overdue' => $contact->last_touch !== null
                    ? (int) $contact->last_touch->startOfDay()->diffInDays($now->startOfDay())
                    : null,
            ])
            ->values()
            ->all();

        return [
            'count' => $this->dueFollowUps->count(),
            'top' => $top,
        ];
    }

    /**
     * Per-stage counts for every pipeline role (the same stages the board
     * renders as columns), plus a total that excludes the terminal stages
     * (REJECTED, WITHDRAWN) since those no longer need attention.
     *
     * @return array{
     *     total_active: int,
     *     stages: list<array{status: string, label: string, count: int}>
     * }
     */
    private function pipelineSummary(): array
    {
        $counts = Role::query()
            ->whereNotNull('pipeline_status')
            ->selectRaw('pipeline_status, COUNT(*) AS role_count')
            ->groupBy('pipeline_status')
            ->pluck('role_count', 'pipeline_status');

        $terminal = [PipelineStatus::REJECTED, PipelineStatus::WITHDRAWN];

        $stages = [];
        $totalActive = 0;
        foreach (PipelineStatus::cases() as $status) {
            $count = (int) ($counts[$status->value] ?? 0);
            $stages[] = [
                'status' => $status->value,
                'label' => self::pipelineStatusLabel($status),
                'count' => $count,
            ];
            if (! in_array($status, $terminal, true)) {
                $totalActive += $count;
            }
        }

        return [
            'total_active' => $totalActive,
            'stages' => $stages,
        ];
    }

    /**
     * Duplicates Pipeline\IndexController::label(); kept identical so the
     * dashboard block and the board use the same wording.
     */
    private static function pipelineStatusLabel(PipelineStatus $status): string
    {
        return match ($status) {
            PipelineStatus::RESEARCHING => 'Researching',
            PipelineStatus::APPLIED => 'Applied',
            PipelineStatus::SCREEN => 'Screen',
            PipelineStatus::ONSITE => 'Onsite',
            PipelineStatus::OFFER => 'Offer',
            PipelineStatus::REJECTED => 'Rejected',
            PipelineStatus::WITHDRAWN => 'Withdrawn',
        };
    }
}
