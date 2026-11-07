<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AggregatorListingStatus;
use App\Enums\PipelineStatus;
use App\Models\AggregatorListing;
use App\Models\Company;
use App\Models\Role;
use App\Pipeline\ApplicationAttention;
use App\Pipeline\AttentionItem;
use App\Scrapers\Aggregator\ActiveAutoDismissRulesQuery;
use App\Sources\SourceHealthQuery;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Renders the action-first dashboard: source health, applications needing
 * attention, a pipeline stage tally, the count of new Discover listings, and a
 * slim watch-list table. The attention count and top rows come from
 * ApplicationAttention, the same definition the Applications page uses.
 */
final readonly class DashboardController
{
    public function __construct(
        private ActiveAutoDismissRulesQuery $autoDismissRules,
        private SourceHealthQuery $sourceHealth,
        private ApplicationAttention $attention,
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
            'applications_attention' => $this->applicationsAttention(),
            'pipeline_summary' => $this->pipelineSummary(),
            'discover_new_count' => AggregatorListing::query()
                ->where('status', AggregatorListingStatus::NEW->value)
                ->count(),
        ]);
    }

    /**
     * @return array{
     *     count: int,
     *     top: list<array{role_id: int, title: string, company_name: string|null, reason: string, reason_label: string, url: string}>
     * }
     */
    private function applicationsAttention(): array
    {
        $items = $this->attention->needingAttention();

        return [
            'count' => $items->count(),
            'top' => $items
                ->take(3)
                ->map(static fn (AttentionItem $item): array => [
                    'role_id' => $item->roleId,
                    'title' => $item->title,
                    'company_name' => $item->companyName,
                    'reason' => $item->reason->value,
                    'reason_label' => $item->describe(),
                    'url' => route('applications.index', ['role' => $item->roleId], false),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Per-stage counts for every pipeline role (the same stages the board
     * renders as columns), plus a total that excludes the terminal stages
     * (REJECTED, WITHDRAWN) since those no longer need attention.
     *
     * @return array{
     *     total_active: int,
     *     stages: list<array{status: string, label: string, terminal: bool, count: int}>
     * }
     */
    private function pipelineSummary(): array
    {
        $counts = Role::query()
            ->whereNotNull('pipeline_status')
            ->selectRaw('pipeline_status, COUNT(*) AS role_count')
            ->groupBy('pipeline_status')
            ->pluck('role_count', 'pipeline_status');

        $stages = [];
        $totalActive = 0;
        foreach (PipelineStatus::cases() as $status) {
            $count = (int) ($counts[$status->value] ?? 0);
            $stages[] = [
                'status' => $status->value,
                'label' => $status->label(),
                'terminal' => $status->isTerminal(),
                'count' => $count,
            ];
            if (! $status->isTerminal()) {
                $totalActive += $count;
            }
        }

        return [
            'total_active' => $totalActive,
            'stages' => $stages,
        ];
    }
}
