<?php

namespace App\Providers;

use App\Stats\Resolver;
use App\Stats\StatRegistry;
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
        //
    }
}
