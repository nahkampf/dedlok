<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Models\Backdoor;
use App\Models\Character;
use App\Models\System;
use Illuminate\Database\Eloquent\Collection;
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
                'installed_at' => now(),
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
     * The nightly ageing roll and a completed trace are what will call this —
     * neither exists yet. It is here rather than left for that pass because
     * the table has one writer and this is it: a delete anywhere else would be
     * a second place that knows what holding a machine means.
     */
    public function lose(Backdoor $backdoor): void
    {
        $backdoor->delete();
    }
}
