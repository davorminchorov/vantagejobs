<?php

declare(strict_types=1);

namespace App\FollowUps;

use App\Enums\PipelineStatus;
use App\Models\Contact;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Encapsulates the "contact follow-up queue" definition shared by the
 * /follow-ups page and the dashboard widget.
 *
 * A contact is due for a follow-up when both hold:
 *  - its last_touch is null or older than config('pipeline.follow_up_threshold_days'), AND
 *  - its company has at least one role in an actively-waiting pipeline stage
 *    (APPLIED or SCREEN).
 *
 * Contacts with no company are never in the queue: without an active
 * application there is nothing to chase. Results are oldest-touch-first
 * (never-touched contacts sort to the very top) so the most overdue surface
 * before fresher ones.
 *
 * All reads run through the Eloquent global tenant scope, so callers never
 * pass a tenant id; the active TenantContext bounds every query.
 */
final readonly class DueFollowUpsQuery
{
    /**
     * Stages that mean the user is waiting on the company and a lull is
     * overdue. Mirrors PipelineFollowUp's flaggable set for the role-card
     * flag, kept local so this slice owns its own definition.
     *
     * @var array<int, string>
     */
    private const ACTIVE_STAGES = [
        'applied',
        'screen',
    ];

    public function __construct(private ConfigRepository $config) {}

    public function thresholdDays(): int
    {
        return (int) $this->config->get('pipeline.follow_up_threshold_days', 7);
    }

    /**
     * Total number of contacts currently due for follow-up. Cheap count for
     * the dashboard badge without hydrating rows.
     */
    public function count(): int
    {
        return $this->baseQuery()->count();
    }

    /**
     * Hydrate the due contacts, oldest-touch-first, eager-loading the company
     * name so the row render never lazy-loads.
     *
     * @return Collection<int, Contact>
     */
    public function get(?int $limit = null): Collection
    {
        $query = $this->baseQuery()
            ->with(['company' => fn (BelongsTo $q) => $q->select(['id', 'name'])])
            ->orderByRaw('last_touch is null desc')
            ->orderBy('last_touch')
            ->orderBy('name');

        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query->get();
    }

    /**
     * Base builder with the two follow-up predicates applied. Exposed shape is
     * an Eloquent builder so callers can paginate, count, or further constrain.
     *
     * @return Builder<Contact>
     */
    public function baseQuery(): Builder
    {
        $cutoff = CarbonImmutable::now()->subDays($this->thresholdDays());

        return Contact::query()
            ->whereNotNull('company_id')
            ->where(function (Builder $query) use ($cutoff): void {
                $query->whereNull('last_touch')
                    ->orWhere('last_touch', '<', $cutoff);
            })
            ->whereHas('company', function (Builder $companyQuery): void {
                $companyQuery->whereHas('roles', function (Builder $roleQuery): void {
                    $roleQuery->whereIn('pipeline_status', self::ACTIVE_STAGES);
                });
            });
    }

    /**
     * The PipelineStatus cases that keep a contact in the queue. Exposed for
     * tests and callers that want to label the queue's scope.
     *
     * @return array<int, PipelineStatus>
     */
    public static function activeStages(): array
    {
        return [
            PipelineStatus::APPLIED,
            PipelineStatus::SCREEN,
        ];
    }
}
