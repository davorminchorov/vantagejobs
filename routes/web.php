<?php

declare(strict_types=1);

use App\Http\Controllers\Companies\CreateCompanyController;
use App\Http\Controllers\Companies\DetectAtsController;
use App\Http\Controllers\Companies\EditCompanyController;
use App\Http\Controllers\Companies\IndexCompaniesController;
use App\Http\Controllers\Companies\StoreCompanyController;
use App\Http\Controllers\Companies\UpdateCompanyController;
use App\Http\Controllers\DashboardController;
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
    Route::get('/companies/{company}/edit', EditCompanyController::class)->name('companies.edit');
    Route::put('/companies/{company}', UpdateCompanyController::class)->name('companies.update');

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
