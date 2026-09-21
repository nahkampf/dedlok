<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Models\Backdoor;
use App\Models\Character;
use App\Models\System;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of `backdoors`, and the one place that answers whether
 * somebody already holds a machine.
 *
 * ## Installing is idempotent, and the kind is not
 *
 * One row per (character, system) by unique index, so going back in on a
 * machine you already hold does not make a second backdoor. What it does NOT
 * do is change the kind: a system siphons or it routes, decided at install,
 * and changing your mind means doing the run again. `install()` therefore
 * leaves an existing row exactly as it is and says so by returning it
 * unchanged — a silent overwrite would let somebody reroll a siphon into a
 * routing hop for free, which is the one thing the kind being permanent
 * exists to prevent.
 *
 * ## What holding one is worth
 *
 * The approach roll is skipped on a machine you hold — `Simulator::rouse()`
 * asks `heldOn()`. It buys nothing at the core: extracting through a backdoor
 * is still stealing, still rolls, still counts toward the machine's three
 * raids and still locks it down if it is caught. Half the risk permanently,
 * not a bypass of the brake.
 */
final readonly class Backdoors
{
    /** @param  Closure(int, int): int  $roll  A percentile draw, like every other die here. */
    public function __construct(
        private ?RaidLedger $raids = null,
        private ?Closure $roll = null,
    ) {}

    /** @param  ?Closure(int, int): int  $roll */
    public static function fromConfig(?Closure $roll = null): self
    {
        $roll ??= static fn (int $min, int $max): int => random_int($min, $max);

        return new self(RaidLedger::fromConfig($roll), $roll);
    }

    /**
     * Put one in, or leave the one that is already there alone.
     *
     * Returns the row either way. The caller knows whether it was new by
     * asking `wasRecentlyCreated`, which is what the run log uses to tell
     * "you are in" from "you were already in".
     */
    public function install(Character $character, System $system, BackdoorKind $kind): Backdoor
    {
        return DB::transaction(static fn (): Backdoor => Backdoor::query()->firstOrCreate(
            [
                'character_id' => $character->getKey(),
                'system_id' => $system->getKey(),
            ],
            [
                'kind' => $kind->value,
                'installed_at' => CarbonImmutable::now(),
            ],
        ));
    }

    /** What this character holds on that machine, or null for nothing. */
    public function heldOn(Character $character, System $system): ?Backdoor
    {
        return Backdoor::query()
            ->where('character_id', $character->getKey())
            ->where('system_id', $system->getKey())
            ->first();
    }

    /**
     * Is there one on the machine at this address?
     *
     * By address rather than by model, for the callers that hold a run — which
     * carries an address and never a system id. A machine that has gone
     * answers false rather than throwing, like every other address read.
     */
    public function heldAt(Character $character, string $address): ?Backdoor
    {
        return Backdoor::query()
            ->where('character_id', $character->getKey())
            ->whereIn('system_id', System::query()->where('address', $address)->select('id'))
            ->first();
    }

    /**
     * Everything this character holds, with the machines they open.
     *
     * Eager-loaded because every caller wants the position or the name: the
     * map marks the cells, and the chain picker will list them by machine.
     *
     * @return Collection<int, Backdoor>
     */
    public function heldBy(Character $character, ?BackdoorKind $kind = null): Collection
    {
        $query = Backdoor::query()
            ->where('character_id', $character->getKey())
            ->with('system');

        if ($kind !== null) {
            $query->ofKind($kind);
        }

        return $query->get();
    }

    /**
     * Give up a backdoor.
     *
     * The nightly ageing roll calls it, and a completed trace will when the
     * chain exists. The table has one writer and this is it: a delete
     * anywhere else would be a second place that knows what holding a machine
     * means.
     */
    public function lose(Backdoor $backdoor): void
    {
        $backdoor->delete();
    }

    /**
     * EVERY door into this machine, gone — including other people's.
     *
     * A BACKDOOR IS NOT PERMANENT AND ITS OWNER IS NOT THE ONLY ONE WHO CAN
     * SPEND IT. Somebody else's run can shut the machine, and a machine that
     * has shut audits itself: it finds the way in and closes it. So the holder
     * can lose access to a system they were careful with, because a stranger
     * was not — which is the same shared world that makes the raid counter a
     * counter on the machine rather than on the person.
     *
     * Asked in two places on purpose, and it is one rule with two alarms:
     * `Lockdown::shut()`, which is when the machine finds out, and
     * `RaidLedger::relocate()`, which is the physical fact — a door into a box
     * that has moved is not a door. Deleting nothing is an ordinary answer, so
     * asking twice costs nothing and neither caller has to know about the
     * other. A BURN needs no entry here at all: it destroys the row, and the
     * foreign key cascades.
     */
    public function loseAllOn(System $system): int
    {
        return Backdoor::query()->where('system_id', $system->getKey())->delete();
    }

    /**
     * The nightly ageing roll: one per backdoor, odds rising with age.
     *
     * THE ONLY THING THAT TAKES A BACKDOOR FROM SOMEBODY WHO DID NOTHING
     * WRONG, and it is what stops a portfolio from being an accumulation. The
     * odds read age and nothing else — not the machine, not what has been
     * done through it — so holding one carefully cannot make it last.
     *
     * A hit removes the backdoor AND MOVES THE MACHINE, because what happened
     * is that somebody found the way in. That takes every other door into the
     * same machine with it, through `relocate()` asking `loseAllOn()`.
     *
     * Ordered by age so the oldest is rolled first, which matters only when
     * the grid is too full for every move to land: the one that has been
     * exposed longest gets the first free position.
     *
     * @return list<array{backdoor: Backdoor, moved: bool}> What was lost.
     */
    public function age(): array
    {
        $config = (array) Config::get('cyberspace.backdoors.ageing', []);
        $base = (float) ($config['base'] ?? 1.0);
        $perDay = (float) ($config['per_day'] ?? 0.05);
        $cap = (float) ($config['cap'] ?? 10.0);

        $lost = [];

        $held = Backdoor::query()->with('system')->orderBy('installed_at')->get();

        foreach ($held as $backdoor) {
            $days = max(0.0, $backdoor->installed_at?->diffInDays(now()) ?? 0.0);
            $chance = min($cap, $base + ($days * $perDay));

            // Percentile, and strictly-below, so a chance of 0 never fires and
            // a chance of 100 always does — the same shape the market's
            // rarity roll uses.
            if (($this->roll ?? static fn (int $min, int $max): int => random_int($min, $max))(1, 10000) > (int) round($chance * 100)) {
                continue;
            }

            $system = $backdoor->system;

            // Delete first: the relocation below will reach for every door on
            // the machine anyway, and a row that has already gone is one the
            // caller can still report on.
            $this->lose($backdoor);

            $lost[] = [
                'backdoor' => $backdoor,
                'moved' => $system !== null && ($this->raids ?? RaidLedger::fromConfig())->relocate($system),
            ];
        }

        return $lost;
    }
}
