<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\Parser\IndexController as AdminParserIndexController;
use App\Http\Controllers\Admin\Parser\ReparseAllController as AdminParserReparseAllController;
use App\Http\Controllers\Admin\Parser\RunRollupController as AdminParserRunRollupController;
use App\Http\Controllers\Companies\CreateCompanyController;
use App\Http\Controllers\Companies\DetectAtsController;
use App\Http\Controllers\Companies\EditCompanyController;
use App\Http\Controllers\Companies\IndexCompaniesController;
use App\Http\Controllers\Companies\ShowCompanyController;
use App\Http\Controllers\Companies\StoreCompanyController;
use App\Http\Controllers\Companies\UpdateCompanyController;
use App\Http\Controllers\Bookmarks\CreateBookmarkController;
use App\Http\Controllers\Bookmarks\DeleteBookmarkController;
use App\Http\Controllers\Bookmarks\IndexController as BookmarksIndexController;
use App\Http\Controllers\DashboardController;
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
use App\Http\Controllers\SavedViews\DestroySavedViewController;
use App\Http\Controllers\SavedViews\StoreSavedViewController;
use App\Http\Controllers\SavedViews\UpdateSavedViewController;
use App\Http\Controllers\Search\GlobalSearchController;
use App\Http\Controllers\Tags\AttachTagController;
use App\Http\Controllers\Tags\AutocompleteTagsController;
use App\Http\Controllers\Tags\DetachTagController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SessionsController;
use App\Http\Controllers\Settings\TwoFactorController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => Inertia::render('Welcome'))->name('home');

Route::middleware(['auth'])->group(function (): void {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::get('/companies', IndexCompaniesController::class)->name('companies.index');
    Route::get('/companies/create', CreateCompanyController::class)->name('companies.create');
    Route::post('/companies', StoreCompanyController::class)->name('companies.store');
    Route::post('/companies/detect-ats', DetectAtsController::class)->name('companies.detect-ats');
    Route::get('/companies/{company}', ShowCompanyController::class)->name('companies.show');
    Route::get('/companies/{company}/edit', EditCompanyController::class)->name('companies.edit');
    Route::put('/companies/{company}', UpdateCompanyController::class)->name('companies.update');

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

    Route::prefix('settings')->name('settings.')->group(function (): void {
        Route::redirect('/', '/settings/profile');

        Route::get('/profile', ProfileController::class)->name('profile');
        Route::get('/password', PasswordController::class)->name('password');
        Route::get('/two-factor', TwoFactorController::class)->name('two-factor');

        Route::get('/sessions', [SessionsController::class, 'index'])->name('sessions');
        Route::post('/sessions/sign-out-others', [SessionsController::class, 'destroyOthers'])->name('sessions.destroy-others');
    });
});
