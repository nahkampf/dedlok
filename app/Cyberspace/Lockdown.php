<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Models\Run;
use App\Models\System;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * A machine that knows it was violated shuts, and when it opens it is somewhere
 * else.
 *
 * ## One rule, and the triggers are instances of it
 *
 * CYBERSPACE.md's table lists four — the IDS fired, the ICE activated, burn,
 * the third raid — and says the single rule underneath is *the machine found
 * out*. Two of those collapse into one question here, because **the ICE can
 * only have activated if something noticed**: detection is what wakes it, so
 * `runs.detected` covers both rows. **Burn collapses too, and downwards**: a
 * burned machine is not shut, it is gone, so `App\Cyberspace\Burner` deletes
 * the row and `afterRun()` then finds nothing to shut — the trigger resolves
 * itself by making its own subject absent. That leaves:
 *
 *  - the run was noticed, whatever else happened in it;
 *  - the raid counter is spent, even if the run was clean. Three raids and it
 *    locks down anyway — somebody eventually reconciles the numbers.
 *
 * **A clean extraction leaves no lockdown behind.** Getting in, taking what it
 * was holding and getting out unseen leaves a machine that does not know
 * anything happened, which is what makes an unnoticed run worth having and
 * what gives a quiet thief somewhere to come back to.
 *
 * ## It fires when the run ENDS
 *
 * "Applied after the run, whatever else happened" — so `RunStarter::end()` is
 * the one caller, the same single door the raid counter is read at. A machine
 * cannot lock down under the runner who caused it: they are already out.
 *
 * ## Anybody ELSE inside is thrown out
 *
 * The one case where a lockdown reaches a live run. It is `RunEnding::Cancelled`
 * — a flat point of Synaptic Integrity and nothing else: no defeat effect, no
 * raid counted, no trace, because the cause was not theirs. `ReadinessWatch`'s
 * reasoning transfers word for word.
 *
 * ## Expiry is SWEPT, and that is the one thing here that is not lazy
 *
 * A scan, a training deposit and the waiting screen all settle when somebody
 * looks. This cannot: two machines relocating into the same free position is a
 * race, and the sweep is what serialises it. `cyberspace:lockdowns` is the
 * caller, every few minutes.
 */
final readonly class Lockdown
{
    /** @param  Closure(int, int): int  $roll */
    public function __construct(
        private RaidLedger $raids,
        private Backdoors $backdoors,
        private Closure $roll,
        private int $minHours,
        private int $maxHours,
    ) {}

    /** @param  ?Closure(int, int): int  $roll */
    public static function fromConfig(?Closure $roll = null): self
    {
        $roll ??= static fn (int $min, int $max): int => random_int($min, $max);

        return new self(
            RaidLedger::fromConfig($roll),
            new Backdoors,
            $roll,
            (int) Config::get('cyberspace.lockdown.hours.min', 2),
            (int) Config::get('cyberspace.lockdown.hours.max', 6),
        );
    }

    /**
     * Did this run leave the machine knowing?
     *
     * Asked as a run ends. Returns whether the machine shut, which is a fact
     * the caller may want and nothing here depends on.
     *
     * A machine that is ALREADY locked down is left alone rather than having
     * its window extended: the window is what the machine does about being
     * violated, not a sentence that accumulates, and a second run inside a
     * lockdown is a case that cannot ordinarily happen anyway.
     */
    public function afterRun(Run $run): bool
    {
        $system = System::query()->where('address', $run->address)->first();

        // A player's fortress is never shut by being noticed or raided: it has its own defences and its own end.
        if ($system === null || $system->type->isFortress() || $system->isLockedDown()) {
            return false;
        }

        /*
         * A BACKDOOR RUN THAT LANDED CLEANS UP AFTER ITSELF, and it is the
         * only thing in cyberspace that does. The install erases the logs and
         * resets the countermeasures it tripped, so the machine does not shut
         * EVEN IF THE IDS FIRED AND THE ICE WOKE UP — that is the whole value
         * of the operation and the reason it is worth doing loudly.
         *
         * `runs.backdoored` and not the purpose: a backdoor run that FAILED
         * cleans up nothing, and the ordinary triggers apply to it like any
         * other run. It is also why the raid counter is untouched here — only
         * an extraction ever moves it, because the books it would read are
         * gone.
         */
        if ($run->backdoored) {
            return false;
        }

        // The counter is read here rather than acted on: the raid ledger says
        // whether the books are spent, and shutting is this class's answer to
        // that — which is what took an immediate relocation out of the ledger.
        if (! $run->detected && ! $this->raids->isSpent($system)) {
            return false;
        }

        $this->shut($system);

        return true;
    }

    /**
     * Shut it, and throw out anybody who was still inside.
     *
     * PUBLIC FOR THE CHAIN BURN. `Simulator::burnTheChain()` shuts every
     * machine a completed trace followed home, which is the same write and the
     * same consequences arrived at from somewhere that is not the end of a run.
     *
     * It was expected that burning a machine would end here too, and that was
     * wrong by one step: a lockdown is a WINDOW, and a burned machine has no
     * window because it has no row. See `App\Cyberspace\Burner`.
     */
    public function shut(System $system): void
    {
        DB::transaction(function () use ($system): void {
            $system->forceFill([
                'locked_down_until' => CarbonImmutable::now()->addHours(($this->roll)($this->minHours, $this->maxHours)),
            ])->save();

            /*
             * A MACHINE THAT HAS FOUND OUT CLOSES THE WAY IN.
             *
             * Everybody's, not only the runner's — and the runner who caused
             * this may not hold one at all. A backdoor keeps a system open
             * against ITS OWN triggers: a clean install leaves no lockdown
             * behind, and a machine you hold does not look up when you arrive.
             * It has never protected against somebody ELSE being caught in
             * there, and the shared world is the whole reason: a stranger's
             * bad night can cost you a door you were careful with, exactly as
             * a stranger's raid can spend a machine's third life.
             */
            $this->backdoors->loseAllOn($system);

            Simulator::fromConfig()->cancelEveryRunOn($system->address);
        });
    }

    /**
     * Relocate every machine whose window has closed.
     *
     * Returns how many moved. One at a time and inside the sweep, because two
     * machines drawing the same free position is exactly the race this is not
     * lazy in order to avoid.
     */
    public function settle(): int
    {
        $moved = 0;

        $due = System::query()
            ->whereNotNull('locked_down_until')
            ->where('locked_down_until', '<=', now())
            ->orderBy('locked_down_until')
            ->get();

        foreach ($due as $system) {
            // Clear the window first, so a machine with nowhere free to move
            // to still OPENS rather than staying shut for ever waiting for a
            // position. A full grid is a seeding problem; a permanently locked
            // machine would be this method's bug.
            $system->forceFill(['locked_down_until' => null])->save();

            if ($this->raids->relocate($system)) {
                $moved++;
            }
        }

        return $moved;
    }
}
