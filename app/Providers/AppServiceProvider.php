<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\AggregatorListing;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
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
     *
     * Morph map is enforced so polymorphic columns store stable string keys
     * ('company', 'role') rather than FQCNs. Adding a new morph target without
     * an entry here throws.
     */
    public function boot(): void
    {
        Relation::enforceMorphMap([
            'company' => Company::class,
            'role' => Role::class,
            'aggregator_listing' => AggregatorListing::class,
            'contact' => Contact::class,
        ]);

        // Stateless closure (no request/config capture) so it is Octane-safe
        // without a container-flush entry.
        Gate::define('admin', fn (User $user): bool => $user->hasRole('admin'));
    }
}
