<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Requests\Settings\UpdateTriageRequest;
use App\Settings\SettingKey;
use App\Settings\SettingsRepository;
use Illuminate\Http\RedirectResponse;

/**
 * Persists the Triage settings sub-page. Validated values are written to the
 * tenant-scoped Setting rows the discover auto-dismiss logic and the dismissed
 * listing retention sweep read at runtime.
 */
final readonly class UpdateTriageController
{
    public function __construct(
        private SettingsRepository $settings,
    ) {}

    public function __invoke(UpdateTriageRequest $request): RedirectResponse
    {
        $this->settings->setMany([
            SettingKey::AUTO_DISMISS_THRESHOLD => $request->integer('auto_dismiss_threshold'),
            SettingKey::DISMISSED_RETENTION_DAYS => $request->integer('dismissed_retention_days'),
            SettingKey::LISTING_EXPIRY_DAYS => $request->integer('listing_expiry_days'),
        ]);

        return redirect()
            ->route('settings.triage')
            ->with('success', 'Triage settings saved.');
    }
}
