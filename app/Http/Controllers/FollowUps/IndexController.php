<?php

declare(strict_types=1);

namespace App\Http\Controllers\FollowUps;

use App\FollowUps\DueFollowUpsQuery;
use App\Models\Contact;
use Carbon\CarbonImmutable;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Render the follow-up queue: contacts whose company has an active
 * application (APPLIED or SCREEN) and who have not been touched within the
 * configured threshold, oldest-first.
 *
 * The page offers a one-click "log touch" action per row that posts to
 * QuickTouchController. The queue definition itself lives in
 * DueFollowUpsQuery so the dashboard widget can consume the same logic.
 */
final readonly class IndexController
{
    public function __invoke(DueFollowUpsQuery $query): Response
    {
        $threshold = $query->thresholdDays();
        $now = CarbonImmutable::now();

        $contacts = $query->get()
            ->map(fn (Contact $contact): array => [
                'id' => $contact->id,
                'name' => $contact->name,
                'role_title' => $contact->role_title,
                'email' => $contact->email,
                'linkedin_url' => $contact->linkedin_url,
                'company_id' => $contact->company_id,
                'company_name' => $contact->company?->name,
                'last_touch' => $contact->last_touch?->toIso8601String(),
                'days_since_touch' => $contact->last_touch !== null
                    ? (int) $contact->last_touch->startOfDay()->diffInDays($now->startOfDay())
                    : null,
            ])
            ->values()
            ->all();

        return Inertia::render('FollowUps/Index', [
            'contacts' => $contacts,
            'threshold_days' => $threshold,
        ]);
    }
}
