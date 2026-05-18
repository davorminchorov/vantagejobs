<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AggregatorListingStatus;
use App\Enums\EventType;
use App\Models\AggregatorListing;
use App\Models\Event;
use App\Signals\Ghost\GhostJobDetector;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Scans live aggregator_listings, runs the ghost-job heuristics, and
 * persists per-listing flags + reason codes. Idempotent: reruns
 * recompute the reasons in place (so newly-arriving layoff Events
 * flip stale listings into ghost-flagged status without re-creating
 * them).
 *
 * Emits a ghost_detected Event the first time a listing tips into
 * ghost_flag=true. Subsequent passes are silent on the timeline so
 * the "ghost density" signal isn't double-counted later by the
 * quality score job.
 */
final class SignalsGhostScanCommand extends Command
{
    protected $signature = 'signals:ghost:scan
        {--tenant= : Tenant slug}';

    protected $description = 'Run ghost-job heuristics across live aggregator_listings and persist flags + reason codes.';

    public function __construct(private readonly GhostJobDetector $detector)
    {
        parent::__construct();
    }

    public function handle(TenantContext $context): int
    {
        $tenant = $this->resolveTenant();
        if (! $tenant instanceof Tenant) {
            return self::FAILURE;
        }
        $context->setCurrent($tenant);

        $startedAt = CarbonImmutable::now();

        $scanned = 0;
        $flagged = 0;
        $newlyFlagged = 0;

        AggregatorListing::query()
            ->whereIn('status', [
                AggregatorListingStatus::NEW,
                AggregatorListingStatus::KEPT,
                AggregatorListingStatus::LINKED,
            ])
            ->orderBy('id')
            ->chunkById(500, function ($chunk) use (&$scanned, &$flagged, &$newlyFlagged): void {
                foreach ($chunk as $listing) {
                    $scanned++;
                    $reasons = $this->detector->detect($listing);
                    $wasGhost = (bool) $listing->ghost_flag;
                    $nowGhost = $reasons !== [];

                    if ($nowGhost) {
                        $flagged++;
                    }

                    if ($nowGhost !== $wasGhost || $listing->ghost_reasons !== $reasons) {
                        $listing->ghost_flag = $nowGhost;
                        $listing->ghost_reasons = $nowGhost ? $reasons : null;
                        $listing->save();
                    }

                    if ($nowGhost && ! $wasGhost && $listing->matched_company_id !== null) {
                        $newlyFlagged++;
                        Event::query()->create([
                            'company_id' => $listing->matched_company_id,
                            'role_id' => null,
                            'type' => EventType::GHOST_DETECTED->value,
                            'title' => 'Possible ghost role: '.($listing->parsed_role_title ?? '(unknown)'),
                            'body' => (string) json_encode([
                                'aggregator_listing_id' => $listing->id,
                                'reasons' => $reasons,
                            ]),
                            'occurred_at' => CarbonImmutable::now(),
                        ]);
                    }
                }
            });

        $finishedAt = CarbonImmutable::now();
        $this->line((string) json_encode([
            'command' => 'signals:ghost:scan',
            'tenant' => $tenant->slug,
            'scanned' => $scanned,
            'currently_flagged' => $flagged,
            'newly_flagged_this_run' => $newlyFlagged,
            'duration_ms' => $finishedAt->diffInMilliseconds($startedAt, true),
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    private function resolveTenant(): ?Tenant
    {
        $slug = $this->option('tenant');
        if (! is_string($slug) || $slug === '') {
            $this->error('The --tenant=<slug> option is required.');

            return null;
        }
        /** @var Tenant|null $tenant */
        $tenant = Tenant::query()->where('slug', $slug)->first();
        if (! $tenant instanceof Tenant) {
            $this->error("Tenant [{$slug}] not found.");

            return null;
        }

        return $tenant;
    }
}
