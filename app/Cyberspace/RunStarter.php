<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Decks\DeckTotals;
use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\Run;
use App\Models\System;
use App\Programs\ProcessRefusal;
use App\Programs\ProgramRunner;
use App\Stats\Resolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * The only thing that begins a run, and the only thing that ends one.
 *
 * ## What it refuses, and the one thing it does not
 *
 * Being in cyberspace, standing on something intrudable, and a deck that still
 * runs. **It does not refuse a machine whose operating system the runner
 * lacks** — that run is ENTERED, and the core is rolled for from the inside.
 * A refusal naming the OS would hand over a scan fact for one click, with no
 * instrument running and no minutes spent, so the gate has to cost a run or it
 * is a lookup. What `os_locked` records is only whether the machine speaks
 * anything at all; how well the runner speaks it is `Simulator`'s roll.
 *
 * ## Readiness is checked twice, and this is the second time
 *
 * Once on jacking in, and again here: cheap, and it closes the window between
 * entering cyberspace and attacking something. `DeckTotals::forCharacter()` is
 * the one place that rule lives, so the gate and the deck screen cannot
 * disagree about a deck.
 *
 * ## One at a time, said by a column
 *
 * `characters.current_run_id` is what makes a character "in a run", so nothing
 * has to enforce that there is only one — a column holds one value. It is set
 * and cleared inside the same transaction that writes the run, because a
 * character pointing at a run that does not exist, or a live run nobody points
 * at, are both states the lock cannot read.
 */
final readonly class RunStarter
{
    public function __construct(
        private Resolver $resolver,
        private ?Lockdown $lockdown = null,
    ) {}

    /**
     * Go in.
     *
     * THE BACKDOOR KIND IS CHOSEN HERE, with the purpose, and never changed —
     * the same call `os_locked` makes. What a machine is worth holding for is
     * something you decide from the map with a scan in front of you, not
     * mid-fight; and the kind is permanent once installed, so the decision
     * wants to be made where the information is.
     *
     * THE LOADOUT IS STARTED HERE TOO, and FIRST — before the arrival roll,
     * because evasion software that came up a moment after the machine looked
     * would have hidden nobody. This is the one caller that starts run-only
     * programs; `end()` is the one that stops them. A program that will not
     * start refuses the whole run with its own reason, and anything already
     * started for it is put back.
     *
     * @param  iterable<InventoryItem>  $loadout
     */
    public function start(Character $character, RunPurpose $purpose, ?BackdoorKind $kind = null, iterable $loadout = []): RunResult
    {
        if ($character->current_run_id !== null) {
            return RunResult::refused(RunRefusal::AlreadyRunning);
        }

        if ($character->current_hunt_id !== null) {
            return RunResult::refused(RunRefusal::Hunted);
        }

        $at = $character->gridPosition();

        if ($at === null) {
            return RunResult::refused(RunRefusal::NotJackedIn);
        }

        $system = System::query()->at($at)->first();

        if ($system === null) {
            return RunResult::refused(RunRefusal::NothingThere);
        }

        if (! $system->type->isInteractable()) {
            return RunResult::refused(RunRefusal::NotIntrudable);
        }

        // SHUT. It found out about somebody, and until the window closes there
        // is no way in — after which there is no machine here at all, because
        // what ends a lockdown is the machine moving.
        if ($system->isLockedDown()) {
            return RunResult::refused(RunRefusal::LockedDown);
        }

        $deck = $character->equippedDeck;

        // No deck at all is the emptiest possible failure of readiness, and it
        // is the same refusal: what the player has to do about it is identical.
        if ($deck === null || ! DeckTotals::forCharacter($character, $deck, $this->resolver)->isReady()) {
            return RunResult::refused(RunRefusal::DeckNotReady);
        }

        $run = DB::transaction(function () use ($character, $system, $at, $purpose, $kind, $loadout): Run|ProcessRefusal {
            $runner = app(ProgramRunner::class);
            $started = [];

            foreach ($loadout as $row) {
                $result = $runner->start($character, $row, duringRun: true);

                if (! $result->succeeded()) {
                    foreach ($started as $process) {
                        $runner->stop($character, $process);
                    }

                    return $result->refusal;
                }

                $started[] = $result->process;
            }

            $run = Run::query()->create([
                'character_id' => $character->getKey(),
                'address' => $system->address,
                'x' => $at->x,
                'y' => $at->y,
                'purpose' => $purpose->value,
                /*
                 * THE ROUTE, SNAPSHOTTED. Declared per run and never held
                 * standing — a backdoor installed after this moment is not on
                 * this run's route, and one lost during it drops out of it.
                 *
                 * Built rather than chosen: every routing backdoor is free to
                 * include and hinders by its machine's grade, so a picker
                 * would be a list whose right answer is always "all of them,
                 * best first". The setup screen shows the same list this
                 * writes, so what a player was shown is what they got.
                 */
                'chain' => ChainBuilder::fromConfig($this->resolver)
                    ->for($character, $system)
                    ->map(static fn (System $hop): int => (int) $hop->getKey())
                    ->all(),
                // Only a backdoor run carries one, so an extraction cannot end
                // up holding a permanent property of a machine it never opened.
                'backdoor_kind' => $purpose === RunPurpose::Backdoor ? $kind?->value : null,
                'turn' => 0,
                // Only whether there is anything to get past. Whether they
                // can is rolled at the core, turn by turn.
                'os_locked' => $this->speaksSomething($system),
                'started_at' => CarbonImmutable::now(),
            ]);

            $character->forceFill(['current_run_id' => $run->getKey()])->save();

            /*
             * ARRIVING IS THE FIRST THING ANYTHING GETS TO NOTICE.
             *
             * Every watcher on the machine rolls against the runner's stealth,
             * and any one of them finding them is enough. Nothing is woken by a
             * run that goes unnoticed — which is the whole of what stealth
             * buys, and why the log starts with whether it worked.
             *
             * The IDS's SECOND chance is on touching the core, and nothing
             * touches the core yet.
             */
            $log = Simulator::fromConfig(null, $this->resolver)->rouse($character, $run, $system);

            $run->refresh();
            $run->forceFill(['log' => array_map(
                static fn (array $line): array => $line + ['turn' => 0],
                $log,
            )])->save();

            return $run;
        });

        if ($run instanceof ProcessRefusal) {
            return RunResult::programRefused($run);
        }

        return RunResult::started($run);
    }

    /**
     * End it, whatever the reason.
     *
     * The run row keeps its ending and the character stops pointing at it, in
     * one transaction — half of that is a state the lock cannot read.
     *
     * A run that has already finished is not an error: the screen showing a
     * button and the press that follows it are minutes apart, and a run can end
     * under a player in between.
     */
    public function end(Character $character, Run $run, RunEnding $ending): RunResult
    {
        if (! $run->isLive()) {
            return RunResult::ended($run);
        }

        DB::transaction(function () use ($character, $run, $ending): void {
            $run->forceFill(['ended_at' => CarbonImmutable::now(), 'ending' => $ending->value])->save();

            // Only if they are still pointing at THIS run. A character who
            // somehow moved on is not dragged back by an old row finishing.
            if ($character->current_run_id === $run->getKey()) {
                $character->forceFill(['current_run_id' => null])->save();
            }

            // Nothing run-only outlives the run that started it.
            app(ProgramRunner::class)->stopRunOnly($character);

            $this->chargeTheTrace($character, $run, $ending);

            /*
             * DOES THE MACHINE NOW KNOW?
             *
             * Asked HERE because this is the one door out of a run — the turn
             * that egresses, the ending the simulator resolves, and the older
             * standalone egress endpoint all arrive at this method, and a
             * consequence any of them could skip is a consequence that depends
             * on how somebody happened to leave.
             *
             * Two ways it finds out and `Lockdown` owns both: the run was
             * noticed, or the raid counter is spent. Asked on EVERY ending
             * rather than only a successful one, because neither fact is about
             * how the run went — a raider who emptied the shelf and then lost
             * the fight has still emptied it, and a machine that saw somebody
             * saw them whatever became of them afterwards.
             *
             * After the row is marked ended, so the runner who caused it is
             * already out: a lockdown throws out everybody still inside, and
             * that must never mean the person it was a consequence of.
             */
            ($this->lockdown ?? Lockdown::fromConfig())->afterRun($run);
        });

        return RunResult::ended($run);
    }

    /**
     * A completed trace's heat, charged as the run ENDS rather than as the
     * trace lands.
     *
     * THE TWO HEATS NEVER COMPOUND. A burn that landed has already charged its
     * own heat (grade × 2.5), which is the worse of the two, so a traced burn
     * pays that and not this as well — and that can only be known once the run
     * is over. Every other ending pays: completed, egressed, downed, cancelled.
     *
     * `runs.trace` caps at 100, so 100 is "it reached you". The grade is read
     * off the system as it stands now; one gone by the time the run ends
     * (burned by somebody else, which cancels this run) is charged as the
     * floor grade rather than nothing.
     */
    private function chargeTheTrace(Character $character, Run $run, RunEnding $ending): void
    {
        if ((int) $run->trace < 100) {
            return;
        }

        if ($ending === RunEnding::Completed && $run->purpose === RunPurpose::Burn) {
            return;
        }

        $grade = (int) ($run->system()?->grade ?? 1);

        $character->forceFill([
            'heat' => (float) $character->heat + $grade * (float) Config::get('cyberspace.trace.heat_per_grade', 2),
        ])->save();
    }

    /**
     * Does this machine speak anything that has to be spoken?
     *
     * The whole of the door's interest in the operating system: a machine
     * running one starts the run LOCKED, and getting in is a roll at the core
     * — see `Simulator::opensTheCore()`. A machine running nothing is open to
     * anybody, which is the portal's case and cannot be reached from here
     * anyway.
     *
     * It used to ask whether the runner had the skill at level 1, which made
     * an unfamiliar operating system a wall rather than a difficulty, and made
     * level 2 through 5 worth nothing at all on a run.
     */
    private function speaksSomething(System $system): bool
    {
        return $system->os_skill_id !== null;
    }
}
