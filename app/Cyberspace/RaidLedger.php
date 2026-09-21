<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Models\System;
use Closure;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * A node counts its raids, and the third one retires it.
 *
 * THE ONLY WRITER OF `systems.raids`, and the only thing that moves a machine
 * once it has been placed — the same discipline `Extractor` keeps over
 * `system_contents` and `InjuryLedger` over an accumulating injury. Two
 * numbers, one place, because the reset is not a separate decision from the
 * count: a relocated machine has new books by definition.
 *
 * ## Three by anybody, not three by you
 *
 * The counter is on the SYSTEM. The world is shared, so a node is a resource
 * other people are also emptying, and a per-character counter would have every
 * popular machine raided into the ground by a hundred people who each thought
 * they had two left. It is the arithmetic brake on a loop the detection floor
 * only brakes by luck: a quiet thief gets three good nights out of a system,
 * never an annuity.
 *
 * ## A raid is a RUN, not a haul
 *
 * Counted once per run, the first time that run takes anything — so a raider
 * who pockets the creds on one turn and finishes pulling a file four turns
 * later has raided the machine once. `Simulator` asks at the moment the take
 * lands rather than when the run ends, because the contents are gone from that
 * moment whatever happens to the person carrying them: a raider who flatlines
 * on the way out has still emptied the shelf, and the machine's books do not
 * care whether anybody got home.
 *
 * ## Retiring is relocation, and there is no lockdown yet
 *
 * CYBERSPACE.md has the machine lock down and then relocate when the lockdown
 * ends. **The lockdown WINDOW is not built** — it has three other triggers
 * (the IDS fired, the ICE activated, burn) which all want the same state, the
 * same duration and the same map treatment, and inventing a private version of
 * it for this one trigger would be inventing the thing that pass has to
 * replace. So the third raid relocates, and the window arrives with the other
 * three triggers. `cell--rebooting` is already in the kit waiting for it.
 *
 * NOT WHILE SOMEBODY IS INSIDE. A live run on the address holds the machine in
 * place until it ends: relocating out from under a run is a real ending
 * (`RunEnding::Cancelled`) and it is not this one's to deal — a raid that
 * succeeded should not cancel a stranger's run as a side effect, and the
 * counter is already spent, so the next run to end on the machine retires it.
 * The delay is the price of not building that ending here.
 */
final readonly class RaidLedger
{
    /** @param  Closure(int, int): int  $roll */
    public function __construct(
        private Grid $grid,
        private Closure $roll,
        private int $limit,
    ) {}

    /** @param  ?Closure(int, int): int  $roll */
    public static function fromConfig(?Closure $roll = null): self
    {
        return new self(
            Grid::fromConfig(),
            $roll ?? static fn (int $min, int $max): int => random_int($min, $max),
            (int) Config::get('cyberspace.raids.limit', 3),
        );
    }

    /**
     * One more raid on the machine at this address.
     *
     * Incremented in SQL rather than read-modify-written, so two raids landing
     * at once are two raids. A missing machine is an ordinary no-op: an address
     * stops resolving the moment its machine burns or moves, which is the one
     * thing every reader of an address has to tolerate.
     */
    public function record(string $address): void
    {
        System::query()->where('address', $address)->increment('raids');
    }

    /**
     * Have the books been emptied often enough for somebody to notice?
     *
     * A QUESTION AND NOT AN ACT. It used to relocate the machine on the spot,
     * because the lockdown window had nowhere to live and a counter that did
     * nothing at three would have been a counter that did nothing. Now
     * `App\Cyberspace\Lockdown` asks this and shuts the machine, and the move
     * happens when the window closes — which is what CYBERSPACE.md described
     * all along.
     *
     * That also retired the compromise this class used to carry: a machine was
     * held in place while anybody was inside it, because yanking it out from
     * under a stranger had no ending to resolve to. A lockdown has one.
     */
    public function isSpent(System $system): bool
    {
        return (int) $system->raids >= $this->limit;
    }

    /**
     * New position, new address, books balanced.
     *
     * PUBLIC BECAUSE LOCKDOWN AND BURN BOTH END HERE TOO. Whatever moves a
     * machine, this is the write: the bookmarks pointing at it break, the
     * address dies, every scan anybody was holding on it stops describing
     * anything, and the sighting left behind is forgotten by the next sweep
     * over the old position. None of those is an error anywhere, because all
     * of them were designed around a machine being allowed to go.
     *
     * A grid with nowhere free is an ordinary false rather than an exception —
     * occupancy leaves about 30% of the world empty precisely so relocation
     * has somewhere to move through, and a world that full is a seeding
     * problem rather than this one's to solve.
     */
    public function relocate(System $system): bool
    {
        $position = $this->grid->freePosition($this->roll);

        if ($position === null) {
            return false;
        }

        return DB::transaction(function () use ($system, $position): bool {
            // A DOOR INTO A BOX THAT HAS MOVED IS NOT A DOOR. Asked here as
            // well as at `Lockdown::shut()` because this is the physical fact
            // rather than the machine noticing — the nightly ageing roll
            // relocates without shutting anything, and a burn will too.
            (new Backdoors)->loseAllOn($system);

            $system->forceFill([
                'x' => $position->x,
                'y' => $position->y,
                'address' => $this->freeAddress(),
                'raids' => 0,
            ])->save();

            return true;
        });
    }

    /**
     * An address nothing is using.
     *
     * The unique index is the guarantee; this is the courtesy that keeps a
     * one-in-four-billion collision from throwing inside the transaction that
     * ends somebody's run. `GridSeeder` does the same against a set it holds
     * in memory, which is the right shape for placing a thousand machines and
     * the wrong one for moving a single machine.
     */
    private function freeAddress(): string
    {
        for ($try = 0; $try < 8; $try++) {
            $address = Address::generate();

            if (! System::query()->where('address', $address)->exists()) {
                return $address;
            }
        }

        return Address::generate();
    }
}
