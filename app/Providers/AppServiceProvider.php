<?php

namespace App\Providers;

use App\Models\User;
use App\Stats\Resolver;
use App\Stats\StatRegistry;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The registry is config-derived and immutable, so one instance serves
        // the whole request. The Resolver holds no state beyond it.
        $this->app->singleton(StatRegistry::class, fn (): StatRegistry => StatRegistry::fromConfig());
        $this->app->singleton(Resolver::class, fn ($app): Resolver => new Resolver($app->make(StatRegistry::class)));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /*
         * The only authorisation the game has.
         *
         * One gate rather than a role table: there are two kinds of account,
         * and a permissions system for a set of size two would be a framework
         * standing in for an if. Routes read it as `can:admin`, views as
         * @can('admin').
         */
        Gate::define('admin', fn (User $user): bool => $user->isAdmin());
    }
}
