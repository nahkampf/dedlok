<?php

namespace App\Providers;

use App\Checks\CheckRoller;
use App\Cyberspace\Probe;
use App\Models\User;
use App\Stats\DamageStats;
use App\Stats\Resolver;
use App\Stats\SkillStats;
use App\Stats\StatGraph;
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
        /*
         * The registry is immutable for the life of a request, so one instance
         * serves it. It is config PLUS the stats every skill generates —
         * `skill.<slug>` and `check.<slug>` — because there will be hundreds of
         * skills and a per-skill entry in config/stats.php would be a second
         * place to remember. App\Stats\SkillStats caches that half.
         *
         * Config wins a collision: a hand-declared stat is a deliberate act and
         * a generated one is not, so a skill slugged `focus` cannot shadow the
         * attribute.
         */
        $this->app->singleton(StatRegistry::class, fn (): StatRegistry => StatRegistry::fromDefinitions(
            ((array) config('stats.stats', []))
            + SkillStats::definitions()
            // One per resource and origin. Pure, so unlike the skill stats
            // there is nothing to cache and nothing to drop when content
            // changes — an origin is an enum case.
            + DamageStats::definitions()
        ));

        $this->app->singleton(Resolver::class, fn ($app): Resolver => new Resolver($app->make(StatRegistry::class)));

        /*
         * The die, and the one thing that rolls it so far.
         *
         * Bound rather than constructed at each call site so a test can put a
         * pinned one in the container and have the SCREENS use it — the map
         * settles a matured scan on the way in, and a test about what that
         * settles cannot reach a `Probe::fromConfig()` built inside a
         * controller. The same reason ProgramRunner is injected.
         */
        $this->app->bind(CheckRoller::class, static fn (): CheckRoller => CheckRoller::fromConfig());
        $this->app->bind(Probe::class, static fn ($app): Probe => Probe::fromConfig(
            $app->make(CheckRoller::class),
            $app->make(Resolver::class),
        ));

        /*
         * Which stats are computed from which. Two sources, and they are the
         * two kinds of derived stat there are: the vitals, declared as formulas
         * over attributes in config, and the checks, one per skill, generated.
         *
         * A cycle throws here rather than at a stack overflow later.
         */
        $this->app->singleton(StatGraph::class, function (): StatGraph {
            $dependencies = [];

            foreach ((array) config('stats.formulas', []) as $statId => $formula) {
                $dependencies[(string) $statId] = array_keys((array) $formula);
            }

            return new StatGraph($dependencies + SkillStats::dependencies());
        });
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
