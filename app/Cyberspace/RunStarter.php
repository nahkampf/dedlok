<?php

declare(strict_types=1);

namespace App\Cyberspace;

use App\Decks\DeckTotals;
use App\Models\Character;
use App\Models\Run;
use App\Models\System;
use App\Stats\Resolver;
use Illuminate\Support\Facades\DB;

/**
 * The only thing that begins a run, and the only thing that ends one.
 *
 * ## What it refuses, and the one thing it does not
 *
 * Being in cyberspace, standing on something intrudable, and a deck that still
 * runs. **It does not refuse a machine whose operating system the runner
 * lacks** — that run is ENTERED, and every action against the system fails
 * afterwards. A refusal naming the OS would hand over a scan fact for one
 * click, with no instrument running and no minutes spent, so the gate has to
 * cost a run or it is a lookup. The verdict is written to `os_locked` when the
 * run begins and never shown.
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
    public function __construct(private Resolver $resolver) {}

    public function start(Character $character, RunPurpose $purpose): RunResult
    {
        if ($character->current_run_id !== null) {
            return RunResult::refused(RunRefusal::AlreadyRunning);
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

        $deck = $character->equippedDeck;

        // No deck at all is the emptiest possible failure of readiness, and it
        // is the same refusal: what the player has to do about it is identical.
        if ($deck === null || ! DeckTotals::forCharacter($character, $deck, $this->resolver)->isReady()) {
            return RunResult::refused(RunRefusal::DeckNotReady);
        }

        $run = DB::transaction(function () use ($character, $system, $at, $purpose): Run {
            $run = Run::query()->create([
                'character_id' => $character->getKey(),
                'address' => $system->address,
                'x' => $at->x,
                'y' => $at->y,
                'purpose' => $purpose->value,
                'turn' => 0,
                // Decided once, at the door, and never told to anybody.
                'os_locked' => ! $this->canOperate($character, $system),
                'started_at' => now(),
            ]);

            $character->forceFill(['current_run_id' => $run->getKey()])->save();

            return $run;
        });

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
            $run->forceFill(['ended_at' => now(), 'ending' => $ending->value])->save();

            // Only if they are still pointing at THIS run. A character who
            // somehow moved on is not dragged back by an old row finishing.
            if ($character->current_run_id === $run->getKey()) {
                $character->forceFill(['current_run_id' => null])->save();
            }
        });

        return RunResult::ended($run);
    }

    /**
     * Does the runner have the operating system this machine speaks?
     *
     * The skill at level 1 is the whole of it — knowing an operating system at
     * all is what lets you operate it, and training further buys other things.
     * A machine running nothing is operable by anybody, which is the portal's
     * case and cannot be reached from here anyway.
     */
    private function canOperate(Character $character, System $system): bool
    {
        $slug = $system->operatingSystem()->first()?->slug;

        return $slug === null || $character->hasSkillAtLevel($slug, 1);
    }
}
