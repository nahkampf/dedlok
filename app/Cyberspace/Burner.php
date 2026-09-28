<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Models\System;
use Illuminate\Support\Facades\DB;

/**
 * Taking a machine out of existence.
 *
 * ## Why this is not `RaidLedger::relocate()`
 *
 * A relocation is a MOVE: the same machine, the same grade, the same contents
 * and history, wearing a new address somewhere else. It frees a position and
 * takes one, so the grid's empty reserve is untouched. A burn is the other
 * thing entirely — the machine is gone, its address goes out of existence with
 * it, and the position it held is simply empty until something new is seeded
 * there. CYBERSPACE.md: *dead, then empty, then eventually something new*.
 *
 * ## Why it is not `Lockdown::shut()` either
 *
 * `shut()` is public because burning was expected to end there, and that turned
 * out to be the wrong shape by one step. A lockdown is a WINDOW — a machine
 * that found out, closed for a few hours, reopening somewhere else when the
 * sweep catches it. There is no window here and nothing to reopen: writing
 * `locked_down_until` onto a row and then deleting the row is two statements
 * that disagree, and the sweep would find nothing to settle. What the two share
 * is the consequence rather than the act, and that consequence now lives in one
 * place — see `Simulator::cancelEveryRunOn()`.
 *
 * ## The order is deliberate: delete, THEN cancel
 *
 * The obvious order is the wrong one. Cancelling first means every other
 * runner's ending walks through `RunStarter::end()`, which asks
 * `Lockdown::afterRun()` whether the machine now knows — and a machine that is
 * still standing and has noticed one of them would SHUT, which throws out
 * everybody inside, which cancels the rest of the runs this method is already
 * in the middle of cancelling. A lockdown on a machine about to be deleted is
 * pointless on its own; reaching it through a re-entrant sweep of the same
 * runs is a bug waiting for a busy machine.
 *
 * Deleting first makes all of that unreachable by making the answer honest:
 * `afterRun()` looks the system up by address, finds nothing, and returns
 * false. A burned machine does not lock down, because there is nothing left to
 * shut.
 *
 * ## What the delete takes with it
 *
 * `backdoors.system_id` is a real foreign key and cascades, which is exactly
 * the call that column was making — access to a machine that has gone is
 * nothing at all, unlike a memory of one. `Backdoors::loseAllOn()` is asked
 * first all the same, because a cascade tells nobody and every holder is owed a
 * message. Everything keyed by ADDRESS instead
 * survives and stops describing anything, which is what those tables are for:
 * a scan, a sighting and a run all outlive their target on purpose.
 */
class Burner
{
    /**
     * Burn it.
     *
     * `$except` is the run doing the burning, which must not be cancelled out
     * from under itself — it ended the machine rather than being caught in it,
     * and `Simulator` resolves that run as `RunEnding::Completed` a moment
     * later. Everybody ELSE standing in the machine is a cancellation: the
     * cause was not theirs.
     */
    public function burn(System $system, ?int $except = null): void
    {
        $address = $system->address;

        DB::transaction(function () use ($system, $address, $except): void {
            // The cascade would take the doors anyway; asking first is what
            // tells the people holding them.
            (new Backdoors)->loseAllOn($system);

            $system->delete();

            Simulator::fromConfig()->cancelEveryRunOn($address, $except);
        });
    }
}
