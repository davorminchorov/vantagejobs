<?php

declare(strict_types=1);

namespace App\Http\Controllers\Calendar;

use App\Calendar\CalendarFeedCacheKey;
use App\Calendar\IcalFeedBuilder;
use App\Models\CalendarEvent;
use App\Models\User;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Public, unauthenticated iCal feed. Resolves the owning user by their
 * calendar_feed_token, sets the tenant context to that user's tenant for the
 * duration of the request, and returns a text/calendar VCALENDAR of future
 * events. The body is cached per token for config('pipeline.ical_cache_ttl')
 * seconds; calendar write controllers forget the same key on mutation.
 */
final readonly class FeedController
{
    public function __construct(
        private TenantContext $tenantContext,
        private CacheRepository $cache,
        private ConfigRepository $config,
        private IcalFeedBuilder $builder,
    ) {}

    public function __invoke(string $token): Response
    {
        $user = User::withoutTenantScope()
            ->where('calendar_feed_token', $token)
            ->first();

        if (! $user instanceof User) {
            throw new NotFoundHttpException('Calendar feed not found.');
        }

        $tenant = Tenant::query()->find($user->getAttribute('tenant_id'));

        if (! $tenant instanceof Tenant) {
            throw new NotFoundHttpException('Calendar feed not found.');
        }

        $ttl = (int) $this->config->get('pipeline.ical_cache_ttl', 300);
        $key = CalendarFeedCacheKey::forToken($token);

        $body = $this->cache->remember(
            $key,
            $ttl,
            fn (): string => $this->renderFeed($tenant, $user),
        );

        return new Response($body, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="vantagejobs.ics"',
        ]);
    }

    private function renderFeed(Tenant $tenant, User $user): string
    {
        return $this->tenantContext->withTenant($tenant, function () use ($tenant, $user): string {
            /** @var Collection<int, CalendarEvent> $events */
            $events = CalendarEvent::query()
                ->where('starts_at', '>=', CarbonImmutable::now('UTC'))
                ->orderBy('starts_at')
                ->get();

            $calendarName = (string) $user->getAttribute('name').' — VantageJobs';

            return $this->builder->build($events, (int) $tenant->getKey(), $calendarName);
        });
    }
}
