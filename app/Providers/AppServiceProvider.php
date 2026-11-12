<?php

namespace App\Providers;

use App\Characters\Bounties;
use App\Checks\CheckRoller;
use App\City\Encounters;
use App\Combat\FightEnding;
use App\Combat\Fights;
use App\Cyberspace\ChainBuilder;
use App\Cyberspace\HunterCombat;
use App\Cyberspace\Hunters;
use App\Cyberspace\Lockdown;
use App\Cyberspace\Probe;
use App\Cyberspace\Simulator;
use App\Cyberspace\Wardens;
use App\Models\User;
use App\Stats\CombatStats;
use App\Stats\DamageStats;
use App\Stats\ModifierMemo;
use App\Stats\Resolver;
use App\Stats\SkillStats;
use App\Stats\StatGraph;
use App\Stats\StatRegistry;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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
            // One aim and one weapon-damage stat per weapon class, from the same kind of enum.
            + CombatStats::definitions()
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

        // Fights are bound too, for the same reason: a test pins the die and the SCREEN uses it.
        $this->app->bind(Fights::class, static fn (): Fights => Fights::fromConfig());
        $this->app->bind(FightEnding::class, static fn (): FightEnding => FightEnding::fromConfig());
        $this->app->bind(Probe::class, static fn ($app): Probe => Probe::fromConfig(
            $app->make(CheckRoller::class),
            $app->make(Resolver::class),
        ));

        /*
         * And the turn, for the same two reasons and one more.
         *
         * `Simulator`'s constructor takes an injected die, so the container
         * cannot build one by reflection — which made every controller that
         * type-hinted it a 500 waiting to be requested rather than a
         * dependency. `fromConfig()` is what knows how to assemble one, and
         * this is where that knowledge belongs.
         *
         * Bound to the container's roller, so a test that pins the die gets a
         * pinned SCREEN as well: the run screen quotes the egress chance, and
         * a screen computing it from a different die than the roll would be
         * quoting odds the game does not hold.
         */
        /*
         * The lockdown, for the third time and the same reason: it takes an
         * injected die, so the container cannot build one by reflection and
         * `cyberspace:lockdowns` type-hinting it would be a command that
         * throws rather than a command with a dependency. Binding it also lets
         * a test pin where a relocating machine lands and have the COMMAND use
         * that die.
         */
        $this->app->bind(Wardens::class, static fn (): Wardens => Wardens::fromConfig());
        $this->app->bind(Lockdown::class, static fn (): Lockdown => Lockdown::fromConfig());
        // Its constructor takes the odds as plain numbers, which the container cannot invent: the nightly `characters:bounties` failed on that.
        $this->app->bind(Bounties::class, static fn (): Bounties => Bounties::fromConfig());

        // Not for a die this time — it has none — but because it needs a
        // Resolver and the container cannot reflect its way to `new Backdoors`.
        $this->app->bind(ChainBuilder::class, static fn ($app): ChainBuilder => ChainBuilder::fromConfig(
            $app->make(Resolver::class),
        ));

        $this->app->bind(Simulator::class, static fn ($app): Simulator => Simulator::fromConfig(
            null,
            $app->make(Resolver::class),
            $app->make(CheckRoller::class),
        ));

        // A hunter's own two dice, for the same reason as every other one
        // above: each takes an injected die, so the container cannot build
        // one by reflection, and binding lets a test pin where a hunter's
        // grade lands or how a fight rolls and have the SCREEN use it too.
        $this->app->bind(Hunters::class, static fn ($app): Hunters => Hunters::fromConfig(
            $app->make(Resolver::class),
        ));
        // The spawn die, bound for the same reason: a test pins it and the SCREEN uses it too.
        $this->app->bind(Encounters::class, static fn ($app): Encounters => Encounters::fromConfig(
            $app->make(Resolver::class),
        ));
        $this->app->bind(HunterCombat::class, static fn ($app): HunterCombat => HunterCombat::fromConfig(
            $app->make(Resolver::class),
        ));

        /*
         * Which stats are computed from which. Two sources, and they are the
         * three kinds of derived stat there are: the vitals, declared as
         * formulas over attributes in config; the pools, which read attributes
         * too and differ only in dividing once rather than per term; and the
         * checks, one per skill, generated.
         *
         * A cycle throws here rather than at a stack overflow later.
         */
        $this->app->singleton(StatGraph::class, function (): StatGraph {
            $dependencies = [];

            foreach ((array) config('stats.formulas', []) as $statId => $formula) {
                $dependencies[(string) $statId] = array_keys((array) $formula);
            }

            // Pools read attributes too — they only differ in the arithmetic —
            // so they belong in the graph for the same reason: resolution order
            // and cycle detection are about WHAT a stat reads, not how it adds
            // it up.
            foreach ((array) config('stats.pools', []) as $statId => $pool) {
                $dependencies[(string) $statId] = array_values((array) $pool['attributes']);
            }

            return new StatGraph($dependencies + SkillStats::dependencies() + CombatStats::dependencies());
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

        // Keeps ModifierMemo true: any write, by model or query builder, and any transaction ending, empties it.
        DB::listen(static function (QueryExecuted $query): void {
            if (! ModifierMemo::active()) {
                return;
            }

            $sql = ltrim(strtolower($query->sql));

            // Writes to these cannot change a modifier, and a page makes some of them on the way past.
            if (str_starts_with($sql, 'select') || preg_match('/^(insert(?: ignore)? into|update|delete from) `(sessions|cache|achievement_progress|user_achievements|notifications|run_party_members)`/', $sql)) {
                return;
            }

            ModifierMemo::flush();
        });
        Event::listen([TransactionCommitted::class, TransactionRolledBack::class], static fn () => ModifierMemo::flush());
    }
}
