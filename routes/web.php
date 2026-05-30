<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\Parser\IndexController as AdminParserIndexController;
use App\Http\Controllers\Admin\Parser\ReparseAllController as AdminParserReparseAllController;
use App\Http\Controllers\Admin\Parser\RunRollupController as AdminParserRunRollupController;
use App\Http\Controllers\Admin\Runs\DownloadArtifactController;
use App\Http\Controllers\Admin\Runs\IndexController as AdminRunsIndexController;
use App\Http\Controllers\Admin\Runs\ShowController as AdminRunsShowController;
use App\Http\Controllers\Companies\CreateCompanyController;
use App\Http\Controllers\Companies\DetectAtsController;
use App\Http\Controllers\Companies\AddFundingController;
use App\Http\Controllers\Companies\EditCompanyController;
use App\Http\Controllers\Companies\GraveyardCompanyController;
use App\Http\Controllers\Companies\IndexCompaniesController;
use App\Http\Controllers\Companies\RestoreCompanyController;
use App\Http\Controllers\Companies\ShowCompanyController;
use App\Http\Controllers\Companies\StoreCompanyController;
use App\Http\Controllers\Companies\UpdateCompanyController;
use App\Http\Controllers\Bookmarks\CreateBookmarkController;
use App\Http\Controllers\Bookmarks\DeleteBookmarkController;
use App\Http\Controllers\Bookmarks\IndexController as BookmarksIndexController;
use App\Http\Controllers\Calendar\DestroyController as CalendarDestroyController;
use App\Http\Controllers\Calendar\FeedController as CalendarFeedController;
use App\Http\Controllers\Calendar\IndexController as CalendarIndexController;
use App\Http\Controllers\Calendar\StoreController as CalendarStoreController;
use App\Http\Controllers\Calendar\UpdateController as CalendarUpdateController;
use App\Http\Controllers\Contacts\ComposeController as ContactsComposeController;
use App\Http\Controllers\Contacts\DestroyController as ContactsDestroyController;
use App\Http\Controllers\Contacts\IndexController as ContactsIndexController;
use App\Http\Controllers\Contacts\Outreach\StoreController as ContactsOutreachStoreController;
use App\Http\Controllers\Contacts\ShowController as ContactsShowController;
use App\Http\Controllers\Contacts\StoreController as ContactsStoreController;
use App\Http\Controllers\Contacts\UpdateController as ContactsUpdateController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FollowUps\IndexController as FollowUpsIndexController;
use App\Http\Controllers\FollowUps\QuickTouchController as FollowUpsQuickTouchController;
use App\Http\Controllers\Discover\BookmarkListingController;
use App\Http\Controllers\Discover\DismissListingController;
use App\Http\Controllers\Discover\IndexController as DiscoverIndexController;
use App\Http\Controllers\Discover\KeepListingController;
use App\Http\Controllers\Discover\ReparseController;
use App\Http\Controllers\Discover\SaveCorrectionController;
use App\Http\Controllers\Discover\SaveFixtureController;
use App\Http\Controllers\Discover\SnoozeListingController;
use App\Http\Controllers\Discover\Sources\IndexController as SourcesIndexController;
use App\Http\Controllers\Discover\Sources\RevokeAutoDismissController;
use App\Http\Controllers\Discover\Sources\RunSourceController;
use App\Http\Controllers\Discover\Sources\ToggleSourceController;
use App\Http\Controllers\Import\CancelImportController;
use App\Http\Controllers\Import\MapFormController;
use App\Http\Controllers\Import\RunImportController;
use App\Http\Controllers\Import\UploadCsvController;
use App\Http\Controllers\Import\UploadFormController;
use App\Http\Controllers\Insights\CompInsightsController;
use App\Http\Controllers\Insights\ConfirmFundingCandidateController;
use App\Http\Controllers\Insights\DismissFundingCandidateController;
use App\Http\Controllers\Insights\FundingCandidatesController;
use App\Http\Controllers\Insights\HiringInsightsController;
use App\Http\Controllers\Pipeline\AddRoleController;
use App\Http\Controllers\Pipeline\IndexController as PipelineIndexController;
use App\Http\Controllers\Pipeline\Offers\ComparisonController as PipelineOffersComparisonController;
use App\Http\Controllers\Pipeline\Offers\UpsertController as PipelineOffersUpsertController;
use App\Http\Controllers\Pipeline\RemoveRoleController;
use App\Http\Controllers\Pipeline\ShowRoleController;
use App\Http\Controllers\Pipeline\StageNotes\StoreController as PipelineStageNotesStoreController;
use App\Http\Controllers\Pipeline\UpdateApplicationController;
use App\Http\Controllers\Pipeline\UpdateStatusController as PipelineUpdateStatusController;
use App\Http\Controllers\SavedViews\DestroySavedViewController;
use App\Http\Controllers\SavedViews\StoreSavedViewController;
use App\Http\Controllers\SavedViews\UpdateSavedViewController;
use App\Http\Controllers\Search\GlobalSearchController;
use App\Http\Controllers\Tags\AttachTagController;
use App\Http\Controllers\Tags\AutocompleteTagsController;
use App\Http\Controllers\Tags\DetachTagController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\Settings\CalendarTokenController;
use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SessionsController;
use App\Http\Controllers\Settings\TwoFactorController;
use App\Http\Controllers\Templates\DestroyController as TemplatesDestroyController;
use App\Http\Controllers\Templates\IndexController as TemplatesIndexController;
use App\Http\Controllers\Templates\StoreController as TemplatesStoreController;
use App\Http\Controllers\Templates\UpdateController as TemplatesUpdateController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => Inertia::render('Welcome'))->name('home');

// Public, unauthenticated iCal subscription feed. Resolved by per-user token.
Route::get('/calendar/{token}/feed.ics', CalendarFeedController::class)->name('calendar.feed');

Route::middleware(['auth'])->group(function (): void {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::get('/companies', IndexCompaniesController::class)->name('companies.index');
    Route::get('/companies/create', CreateCompanyController::class)->name('companies.create');
    Route::post('/companies', StoreCompanyController::class)->name('companies.store');
    Route::post('/companies/detect-ats', DetectAtsController::class)->name('companies.detect-ats');
    Route::get('/companies/{company}', ShowCompanyController::class)->name('companies.show');
    Route::get('/companies/{company}/edit', EditCompanyController::class)->name('companies.edit');
    Route::put('/companies/{company}', UpdateCompanyController::class)->name('companies.update');
    Route::post('/companies/{company}/graveyard', GraveyardCompanyController::class)->name('companies.graveyard');
    Route::post('/companies/{company}/restore', RestoreCompanyController::class)->name('companies.restore');
    Route::post('/companies/{company}/funding', AddFundingController::class)->name('companies.funding.store');

    Route::get('/insights/hiring', HiringInsightsController::class)->name('insights.hiring');
    Route::get('/insights/comp', CompInsightsController::class)->name('insights.comp');
    Route::get('/insights/funding-candidates', FundingCandidatesController::class)
        ->name('insights.funding-candidates.index');
    Route::post('/insights/funding-candidates/{candidate}/confirm', ConfirmFundingCandidateController::class)
        ->whereNumber('candidate')
        ->name('insights.funding-candidates.confirm');
    Route::post('/insights/funding-candidates/{candidate}/dismiss', DismissFundingCandidateController::class)
        ->whereNumber('candidate')
        ->name('insights.funding-candidates.dismiss');

    Route::get('/import', UploadFormController::class)->name('import.upload');
    Route::post('/import', UploadCsvController::class)->name('import.upload.store');
    Route::get('/import/map', MapFormController::class)->name('import.map');
    Route::post('/import/run', RunImportController::class)->name('import.run');
    Route::post('/import/cancel', CancelImportController::class)->name('import.cancel');

    Route::get('/discover', DiscoverIndexController::class)->name('discover.index');
    Route::post('/discover/{listing}/keep', KeepListingController::class)->name('discover.listings.keep');
    Route::post('/discover/{listing}/dismiss', DismissListingController::class)->name('discover.listings.dismiss');
    Route::post('/discover/{listing}/snooze', SnoozeListingController::class)->name('discover.listings.snooze');
    Route::post('/discover/{listing}/bookmark', BookmarkListingController::class)->name('discover.listings.bookmark');
    Route::post('/discover/{listing}/corrections', SaveCorrectionController::class)->name('discover.listings.corrections.store');
    Route::post('/discover/{listing}/save-fixture', SaveFixtureController::class)->name('discover.listings.save-fixture');
    Route::post('/discover/{listing}/reparse', ReparseController::class)->name('discover.listings.reparse');

    Route::get('/discover/sources', SourcesIndexController::class)->name('discover.sources.index');
    Route::post('/discover/sources/{source}/toggle', ToggleSourceController::class)->name('discover.sources.toggle');
    Route::post('/discover/sources/{source}/run', RunSourceController::class)->name('discover.sources.run');
    Route::post('/discover/auto-dismiss-rules/{rule}/revoke', RevokeAutoDismissController::class)->name('discover.rules.revoke');

    Route::get('/bookmarks', BookmarksIndexController::class)->name('bookmarks.index');
    Route::post('/bookmarks/listings/{listing}', CreateBookmarkController::class)->name('bookmarks.listings.create');
    Route::delete('/bookmarks/{bookmark}', DeleteBookmarkController::class)->name('bookmarks.destroy');

    Route::get('/search', GlobalSearchController::class)->name('search');

    Route::get('/pipeline', PipelineIndexController::class)->name('pipeline.index');
    Route::post('/pipeline/status', PipelineUpdateStatusController::class)->name('pipeline.status.update');
    Route::get('/pipeline/offers/comparison', PipelineOffersComparisonController::class)->name('pipeline.offers.comparison');
    Route::post('/pipeline/roles/{role}/add', AddRoleController::class)
        ->whereNumber('role')
        ->name('pipeline.roles.add');
    Route::delete('/pipeline/roles/{role}/remove', RemoveRoleController::class)
        ->whereNumber('role')
        ->name('pipeline.roles.remove');
    Route::get('/pipeline/roles/{role}', ShowRoleController::class)
        ->whereNumber('role')
        ->name('pipeline.roles.show');
    Route::put('/pipeline/roles/{role}/application', UpdateApplicationController::class)
        ->whereNumber('role')
        ->name('pipeline.roles.application.update');
    Route::post('/pipeline/roles/{role}/stage-notes', PipelineStageNotesStoreController::class)
        ->whereNumber('role')
        ->name('pipeline.roles.stage-notes.store');
    Route::post('/pipeline/roles/{role}/offer', PipelineOffersUpsertController::class)
        ->whereNumber('role')
        ->name('pipeline.roles.offer.upsert');

    Route::get('/contacts', ContactsIndexController::class)->name('contacts.index');
    Route::post('/contacts', ContactsStoreController::class)->name('contacts.store');
    Route::get('/contacts/{contact}', ContactsShowController::class)
        ->whereNumber('contact')
        ->name('contacts.show');
    Route::put('/contacts/{contact}', ContactsUpdateController::class)
        ->whereNumber('contact')
        ->name('contacts.update');
    Route::delete('/contacts/{contact}', ContactsDestroyController::class)
        ->whereNumber('contact')
        ->name('contacts.destroy');
    Route::post('/contacts/{contact}/compose', ContactsComposeController::class)
        ->whereNumber('contact')
        ->name('contacts.compose');
    Route::post('/contacts/{contact}/outreach', ContactsOutreachStoreController::class)
        ->whereNumber('contact')
        ->name('contacts.outreach.store');

    Route::get('/templates', TemplatesIndexController::class)->name('templates.index');
    Route::post('/templates', TemplatesStoreController::class)->name('templates.store');
    Route::put('/templates/{template}', TemplatesUpdateController::class)
        ->whereNumber('template')
        ->name('templates.update');
    Route::delete('/templates/{template}', TemplatesDestroyController::class)
        ->whereNumber('template')
        ->name('templates.destroy');

    Route::get('/follow-ups', FollowUpsIndexController::class)->name('follow-ups.index');
    Route::post('/follow-ups/{contact}/touch', FollowUpsQuickTouchController::class)
        ->whereNumber('contact')
        ->name('follow-ups.touch');

    Route::get('/calendar', CalendarIndexController::class)->name('calendar.index');
    Route::post('/calendar', CalendarStoreController::class)->name('calendar.store');
    Route::put('/calendar/{calendarEvent}', CalendarUpdateController::class)
        ->whereNumber('calendarEvent')
        ->name('calendar.update');
    Route::delete('/calendar/{calendarEvent}', CalendarDestroyController::class)
        ->whereNumber('calendarEvent')
        ->name('calendar.destroy');

    Route::prefix('admin/parser')->name('admin.parser.')->group(function (): void {
        Route::get('/', AdminParserIndexController::class)->name('index');
        Route::post('/rollup', AdminParserRunRollupController::class)->name('rollup');
        Route::post('/reparse-all', AdminParserReparseAllController::class)->name('reparse-all');
    });

    Route::post('/saved-views', StoreSavedViewController::class)->name('saved-views.store');
    Route::patch('/saved-views/{view}', UpdateSavedViewController::class)
        ->whereNumber('view')
        ->name('saved-views.update');
    Route::delete('/saved-views/{view}', DestroySavedViewController::class)
        ->whereNumber('view')
        ->name('saved-views.destroy');

    Route::get('/tags', AutocompleteTagsController::class)->name('tags.autocomplete');
    Route::post('/companies/{company}/tags', AttachTagController::class)->name('companies.tags.attach');
    Route::delete('/companies/{company}/tags/{tag}', DetachTagController::class)->name('companies.tags.detach');

    Route::get('/health', HealthController::class)->name('health');

    Route::prefix('admin')->name('admin.')->group(function (): void {
        Route::get('/runs', AdminRunsIndexController::class)->name('runs.index');
        Route::get('/runs/artifacts/{artifact}/download', DownloadArtifactController::class)
            ->whereNumber('artifact')
            ->name('runs.artifacts.download');
        Route::get('/runs/{run}', AdminRunsShowController::class)
            ->whereNumber('run')
            ->name('runs.show');

        Route::prefix('parser')->name('parser.')->group(function (): void {
            Route::get('/', AdminParserIndexController::class)->name('index');
            Route::post('/rollup', AdminParserRunRollupController::class)->name('rollup');
            Route::post('/reparse-all', AdminParserReparseAllController::class)->name('reparse-all');
        });
    });

    Route::prefix('settings')->name('settings.')->group(function (): void {
        Route::redirect('/', '/settings/profile');

        Route::get('/profile', ProfileController::class)->name('profile');
        Route::get('/password', PasswordController::class)->name('password');
        Route::get('/two-factor', TwoFactorController::class)->name('two-factor');

        Route::get('/sessions', [SessionsController::class, 'index'])->name('sessions');
        Route::post('/sessions/sign-out-others', [SessionsController::class, 'destroyOthers'])->name('sessions.destroy-others');

        Route::get('/calendar', [CalendarTokenController::class, 'index'])->name('calendar');
        Route::post('/calendar/regenerate', [CalendarTokenController::class, 'regenerate'])->name('calendar.regenerate');
    });
});
