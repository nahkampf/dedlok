<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Checks\CheckRoller;
use App\Models\Character;
use App\Models\Hunt;
use App\Models\Warden;
use App\Stats\Resolver;
use Closure;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Wardens (docs/CYBERSPACE.md, *Wardens*), and the only writer of `wardens`.
 *
 * A Warden is a hunter that lives in a subnet: it stands on the grid, takes one random step a minute, and when a runner comes
 * within its reach (1 or 2 steps, by grade) it makes a check against the runner's Stealth. A runner who fails it is attacked at
 * once, as a hunter is (`Hunters::spawn()`, so the fight, the loadout and the payout are the Bloodhound's), and beating that
 * hunter removes the Warden. The check is made when the runner ARRIVES near one and when a Warden steps near a runner.
 *
 * Movement is lazy and also swept: a Warden is moved up to `catch_up` steps when somebody looks, and the minute clock
 * (`wardens:move`) keeps them true while nobody does.
 */
final class Wardens
{
    /** @param  Closure(int, int): int  $roll */
    public function __construct(
        private readonly Resolver $resolver,
        private readonly CheckRoller $checks,
        private readonly Closure $roll,
    ) {}

    /** @param  ?Closure(int, int): int  $roll */
    public static function fromConfig(?Closure $roll = null): self
    {
        return new self(app(Resolver::class), app(CheckRoller::class), $roll ?? static fn (int $min, int $max): int => random_int($min, $max));
    }

    /** How many steps away a Warden of this grade notices a runner. */
    public static function reach(int $grade): int
    {
        return (int) Config::get("cyberspace.wardens.reach.{$grade}", 1);
    }

    /**
     * Stand Wardens on a subnet up to its spec (`grids.spec.wardens`: count, grade_min, grade_max), at most `$limit` new ones.
     * They never start within `keep_away` steps of the entry square, so nobody is ambushed on arrival.
     *
     * @return int How many were made.
     */
    public function populate(int $gridId, ?int $limit = null): int
    {
        if ($gridId === 1) {
            return 0;
        }

        $whole = (array) json_decode((string) DB::table('grids')->where('id', $gridId)->value('spec'), true);
        $spec = (array) ($whole['wardens'] ?? []);
        $want = (int) ($spec['count'] ?? 0);
        $missing = max(0, $want - Warden::query()->where('grid_id', $gridId)->count());

        if ($limit !== null) {
            $missing = min($missing, $limit);
        }

        $grid = Grid::forId($gridId);
        $entry = $grid->portal();
        $keep = (int) Config::get('cyberspace.wardens.keep_away', 4);
        [$low, $high] = [(int) ($spec['grade_min'] ?? 1), (int) ($spec['grade_max'] ?? 1)];
        $made = 0;

        for ($i = 0; $i < $missing; $i++) {
            for ($try = 0; $try < 50; $try++) {
                $x = ($this->roll)(0, $grid->size() - 1);
                $y = ($this->roll)(0, $grid->size() - 1);

                if (max(abs($x - $entry->x), abs($y - $entry->y)) < $keep && $try < 49) {
                    continue;
                }

                Warden::query()->create(['grid_id' => $gridId, 'x' => $x, 'y' => $y, 'grade' => ($this->roll)($low, max($low, $high)), 'moved_at' => now()]);
                $made++;

                break;
            }
        }

        return $made;
    }

    /**
     * Move every Warden of the grid that is due: one random step per minute since it last moved, at most `catch_up`.
     *
     * @return list<Warden> The ones that moved.
     */
    public function settle(int $gridId): array
    {
        $step = max(1, (int) Config::get('cyberspace.wardens.step_seconds', 60));
        $catchUp = max(1, (int) Config::get('cyberspace.wardens.catch_up', 3));
        $grid = Grid::forId($gridId);
        $moved = [];

        foreach (Warden::query()->where('grid_id', $gridId)->where('moved_at', '<=', now()->subSeconds($step))->get() as $warden) {
            $steps = min($catchUp, max(1, intdiv((int) $warden->moved_at->diffInSeconds(now()), $step)));

            for ($i = 0; $i < $steps; $i++) {
                $this->step($warden, $grid);
            }

            $warden->forceFill(['moved_at' => now()])->save();
            $moved[] = $warden;
        }

        return $moved;
    }

    /** One random step to a neighbouring square of the grid. */
    private function step(Warden $warden, Grid $grid): void
    {
        $options = [];

        for ($dx = -1; $dx <= 1; $dx++) {
            for ($dy = -1; $dy <= 1; $dy++) {
                if (($dx !== 0 || $dy !== 0) && $grid->contains(new Position($warden->x + $dx, $warden->y + $dy, $grid->id()))) {
                    $options[] = [$warden->x + $dx, $warden->y + $dy];
                }
            }
        }

        [$warden->x, $warden->y] = $options[($this->roll)(0, count($options) - 1)];
    }

    /**
     * A runner has arrived (or stands where a Warden has stepped): does a Warden within reach notice them? The first that does
     * attacks, and the hunt it starts is returned.
     */
    public function notice(Character $character, ?iterable $wardens = null): ?Hunt
    {
        $at = $character->gridPosition();

        if ($at === null || $at->grid === 1 || $this->busy($character)) {
            return null;
        }

        $wardens ??= Warden::query()->where('grid_id', $at->grid)->get();

        foreach ($wardens as $warden) {
            if (max(abs($warden->x - $at->x), abs($warden->y - $at->y)) > self::reach($warden->grade)) {
                continue;
            }

            $difficulty = (float) Config::get("cyberspace.wardens.detection.{$warden->grade}", 50);

            // The runner rolls to go unnoticed; a failure is a Warden that saw them.
            if ($this->checks->roll($character->stat('stealth')->value, $difficulty, 'detection')->passed) {
                continue;
            }

            return Hunters::fromConfig($this->resolver, $this->roll)->spawn($character, $warden->grade, $warden->getKey());
        }

        return null;
    }

    /** On arrival: the Wardens of this grid catch up on their steps, then any within reach take a look. */
    public function ambush(Character $character): ?Hunt
    {
        $at = $character->gridPosition();

        if ($at === null || $at->grid === 1) {
            return null;
        }

        $this->settle($at->grid);

        return $this->notice($character);
    }

    /**
     * The minute clock: every subnet's Wardens move, a missing one is replaced (one a minute), and a runner a moved Warden
     * has come near is looked at.
     *
     * @return int How many Wardens moved.
     */
    public function minute(): int
    {
        $moved = 0;

        foreach (DB::table('grids')->where('id', '>', 1)->pluck('id') as $gridId) {
            $this->populate((int) $gridId, 1);
            $wardens = $this->settle((int) $gridId);
            $moved += count($wardens);

            if ($wardens === []) {
                continue;
            }

            foreach (Character::query()->where('grid_id', $gridId)->whereNotNull('grid_x')->get() as $character) {
                $this->notice($character, $wardens);
            }
        }

        return $moved;
    }

    private function busy(Character $character): bool
    {
        return $character->current_hunt_id !== null || $character->current_run_id !== null || $character->current_fight_id !== null
            || $character->current_encounter_id !== null || $character->waiting_until !== null;
    }
}
