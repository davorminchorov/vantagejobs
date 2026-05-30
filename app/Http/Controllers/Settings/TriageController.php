<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Settings\SettingKey;
use App\Settings\SettingsRepository;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Renders the Triage settings sub-page: the auto-dismiss confidence threshold
 * and how long dismissed listings are retained before the prune sweep deletes
 * them. Both are Setting-backed with inline defaults so a tenant that has never
 * saved still sees sensible values.
 */
final readonly class TriageController
{
    public function __construct(
        private SettingsRepository $settings,
    ) {}

    public function __invoke(Request $request): Response
    {
        return Inertia::render('Settings/Triage', [
            'settings' => [
                'auto_dismiss_threshold' => (int) $this->settings->get(
                    SettingKey::AUTO_DISMISS_THRESHOLD,
                    70,
                ),
                'dismissed_retention_days' => (int) $this->settings->get(
                    SettingKey::DISMISSED_RETENTION_DAYS,
                    30,
                ),
            ],
        ]);
    }
}
