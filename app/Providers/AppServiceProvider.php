<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * Auth-event listeners in app/Listeners/Auth/RecordAuthEvent.php are
     * registered automatically by Laravel's event discovery (every public
     * `handle*` method whose first parameter type-hints an event class is
     * bound to that event). No manual Event::listen() calls are required.
     */
    public function boot(): void
    {
        //
    }
}
