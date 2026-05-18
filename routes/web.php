<?php

declare(strict_types=1);

use App\Http\Controllers\Companies\IndexCompaniesController;
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
