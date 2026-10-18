<?php

declare(strict_types=1);

namespace App\City;

use App\Characters\DownedCause;
use App\Characters\EncounterPause;
use App\Characters\Flags;
use App\Characters\WaitingRoom;
use App\Checks\CheckRoller;
use App\Combat\Fight\Result;
use App\Combat\FightRefusal;
use App\Combat\FightReturn;
use App\Combat\Fights;
use App\Cyberspace\RunEnding;
use App\Cyberspace\RunStarter;
use App\Models\Character;
use App\Models\CharacterEncounter;
use App\Models\EncounterChoice;
use App\Models\EncounterNode;
use App\Models\EncounterOutcome;
use App\Models\Fight;
use App\Models\Run;
use App\Stats\StatRegistry;
use App\Support\Text;
use App\Wallet\Reason;
use App\Wallet\Wallet;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Answers an encounter, one choice at a time.
 *
 * ONE TRANSACTION PER CHOICE, on the locked character and the locked live row. The
 * choice must belong to the CURRENT node, so a double submit — or a stale page —
 * finds the node already moved and is refused rather than replayed. Cost is
 * charged before any roll and a failure does not refund it.
 *
 * `chance()` is `CheckRoller`'s public question and the screen asks it for the
 * button's band word; `roll()` is the click. The two cannot disagree.
 *
 * The only thing that closes an encounter: a choice with nowhere to go, a node
 * that is missing (an editor removed it under somebody), or `leave()` from an
 * ending node. Closing clears the pointer and deletes the live row — the `once`
 * flag was set on entry and is the only lasting record.
 */
final class EncounterRunner
{
    public function __construct(
        private readonly CheckRoller $roller,
        private readonly EncounterOutcomes $outcomes,
        private readonly WaitingRoom $room,
        private readonly StatRegistry $stats,
    ) {}

    /** The chance of a rolled choice, for the button. Null when it has no roll. */
    public function chanceOf(Character $character, EncounterChoice $choice): ?float
    {
        if (! $choice->hasCheck()) {
            return null;
        }

        return $this->roller->chance($this->valueOf($character, $choice), (float) $choice->difficulty, 'encounter');
    }

    /** Whether a choice is on offer to this character at all (its flag, if it names one). */
    public function offered(Character $character, EncounterChoice $choice): bool
    {
        return $choice->requires_flag === null || Flags::has($character, $choice->requires_flag);
    }

    public function choose(Character $character, int $choiceId): EncounterStep|CityRefusal
    {
        return DB::transaction(function () use ($character, $choiceId): EncounterStep|CityRefusal {
            $locked = Character::query()->whereKey($character->getKey())->lockForUpdate()->firstOrFail();
            $live = $this->live($locked);

            if ($live === null) {
                return CityRefusal::NothingHappening;
            }

            $node = $this->nodeOf($live);

            if ($node === null) {
                $this->close($locked, $live);

                return new EncounterStep(true);
            }

            /** @var ?EncounterChoice $choice */
            $choice = $node->ends ? null : $node->choices()->whereKey($choiceId)->first();

            if ($choice === null || ! $this->offered($locked, $choice)) {
                return CityRefusal::NotAChoice;
            }

            // A fight is its own kind of choice: it opens one and waits for how it ends.
            if ($choice->isFight()) {
                return $this->startFight($locked, $live, $choice);
            }

            if ($choice->cost > 0) {
                if ($locked->creds < $choice->cost) {
                    return CityRefusal::CannotPay;
                }

                Wallet::move($locked, -$choice->cost, Reason::Encounter, Text::get('wallet.notes.encounter'));
            }

            $lines = [];
            $passed = null;

            if ($choice->hasCheck()) {
                $passed = $this->roller->roll($this->valueOf($locked, $choice), (float) $choice->difficulty, 'encounter')->passed;
                $lines[] = Text::get($passed ? 'city.encounter.passed' : 'city.encounter.failed');
            }

            $hurt = false;

            /** @var EncounterOutcome $outcome */
            foreach ($choice->outcomes as $outcome) {
                if (! $this->applies($outcome, $passed)) {
                    continue;
                }

                if (($line = $this->outcomes->apply($locked, $outcome)) !== null) {
                    $lines[] = $line;
                }

                $hurt = $hurt || $this->outcomes->dealsDamage($outcome);
            }

            $next = match ($passed) {
                true => $choice->pass_key ?? $choice->next_key,
                false => $choice->fail_key ?? $choice->next_key,
                null => $choice->next_key,
            };

            $closed = $next === null || ! $this->hasNode($live, $next);

            // Arriving is part of the same step: the screen's own outcomes, and its lines are the choice's.
            if (! $closed) {
                [$arrival, $arrivalHurt] = $this->arrive($locked, $this->nodeNamed($live, $next));
                $lines = [...$lines, ...$arrival];
                $hurt = $hurt || $arrivalHurt;
            }

            $live->log = [...(array) $live->log, ['label' => $choice->label, 'lines' => $lines]];

            if ($closed) {
                $this->close($locked, $live);
            } else {
                $live->node_key = $next;
                $live->save();
            }

            // Last, the rule every bad ending follows: damage, consequence, the floor.
            if ($hurt) {
                $this->room->checkFloor($locked, new DownedCause(reason: 'encounter'));
            }

            return new EncounterStep($closed, $lines);
        });
    }

    /**
     * A FIGHT CHOICE: open a fight with the choice's opponent and let it carry on from there. The
     * encounter row STAYS, and `afterFight()` moves it on by how the fight ends.
     *
     * The fight is opened BEFORE the cost is charged: a refusal returned from inside a transaction
     * still commits, so charging first would keep the creds of a fight that never began.
     * `Fights` comes from the container at this moment, never the constructor — it calls back into
     * this class when a fight ends, and a test must be able to pin its dice.
     */
    private function startFight(Character $locked, CharacterEncounter $live, EncounterChoice $choice): EncounterStep|CityRefusal
    {
        $npc = $choice->opponent;

        if ($npc === null) {
            return CityRefusal::NotAChoice;
        }

        if ($choice->cost > 0 && $locked->creds < $choice->cost) {
            return CityRefusal::CannotPay;
        }

        $fight = app(Fights::class)->open($locked, $npc, new FightReturn((int) $live->getKey(), $choice->win_key, $choice->lose_key, $choice->flee_key));

        if ($fight instanceof FightRefusal) {
            return $fight === FightRefusal::InFight ? CityRefusal::Fighting : CityRefusal::NotAChoice;
        }

        if ($choice->cost > 0) {
            Wallet::move($locked, -$choice->cost, Reason::Encounter, Text::get('wallet.notes.encounter'));
        }

        $lines = [Text::get('city.encounter.fight_begins', ['name' => $npc->name])];
        $live->log = [...(array) $live->log, ['label' => $choice->label, 'lines' => $lines]];
        $live->save();

        return new EncounterStep(false, $lines, fight: true);
    }

    /**
     * A fight this encounter started has ended: move the encounter on by how it went.
     *
     * Called by `Fights` inside its own transaction, on the LOCKED character, after the fight is stamped
     * and the player has been put on the floor if they lost. Win goes to the win screen, a loss to the
     * loss screen (read after they get up — the waiting-room lock comes first), and EVERYTHING ELSE to
     * the nobody-won screen: the player got away, or the opponent did, and either way there are no
     * gains, because the win screen is where authored rewards sit.
     *
     * Acts only if the recorded row is still this character's live encounter. Each key is resolved
     * against the nodes as they are NOW, and one that no longer exists, like a null one, closes the
     * encounter — the rule everywhere else. The screen's own arrival outcomes apply exactly as they do
     * when a choice leads there, and the floor is asked once at the end.
     *
     * @param  list<string>  $settled  the lines `FightEnding` produced, already rendered, to sit after the ending line
     */
    public function afterFight(Character $locked, Fight $fight, array $settled = []): void
    {
        if ($fight->encounter_row_id === null || (int) $locked->current_encounter_id !== (int) $fight->encounter_row_id) {
            return;
        }

        $live = CharacterEncounter::query()->whereKey($fight->encounter_row_id)->lockForUpdate()->first();

        if ($live === null || $fight->result === null) {
            return;
        }

        $key = match ($fight->result) {
            Result::BDown => $fight->win_key,
            Result::ADown => $fight->lose_key,
            Result::AEscaped, Result::BEscaped => $fight->flee_key,
        };

        // What the ending was worth (pay, drops, what was taken) goes in the log with the ending line itself.
        $lines = [Text::get('combat.fight.ending.'.$fight->result->value, ['name' => $fight->opponent_name]), ...$settled];
        $closed = $key === null || ! $this->hasNode($live, $key);
        $hurt = false;

        if (! $closed) {
            [$arrival, $hurt] = $this->arrive($locked, $this->nodeNamed($live, $key));
            $lines = [...$lines, ...$arrival];
        }

        $live->log = [...(array) $live->log, ['label' => Text::get('city.encounter.fight_with', ['name' => $fight->opponent_name]), 'lines' => $lines]];

        if ($closed) {
            $this->close($locked, $live);
        } else {
            $live->node_key = $key;
            $live->save();
        }

        // Last, the rule every bad ending follows: damage, consequence, the floor.
        if ($hurt) {
            $this->room->checkFloor($locked, new DownedCause(reason: 'encounter'));
        }
    }

    /**
     * What a screen does to somebody the moment they reach it.
     *
     * Every outcome the screen owns applies, in order, EVERY time it is reached (a loop back to it
     * hurts again). The floor is NOT asked here: the caller asks it once, after everything else.
     *
     * @return array{0: list<string>, 1: bool} the lines to tell the player, and whether any dealt damage
     */
    public function arrive(Character $character, ?EncounterNode $node): array
    {
        $lines = [];
        $hurt = false;

        /** @var EncounterOutcome $outcome */
        foreach ($node?->outcomes ?? [] as $outcome) {
            if (($line = $this->outcomes->apply($character, $outcome)) !== null) {
                $lines[] = $line;
            }

            $hurt = $hurt || $this->outcomes->dealsDamage($outcome);
        }

        return [$lines, $hurt];
    }

    /**
     * A character has just been put on an encounter's opening screen: apply it, write what it said
     * as the first line of the log, and ask the floor. Called by `Encounters::open()` only.
     */
    public function begin(Character $character, CharacterEncounter $live, EncounterNode $entry): void
    {
        [$lines, $hurt] = $this->arrive($character, $entry);

        if ($lines !== []) {
            $live->forceFill(['log' => [['label' => '', 'lines' => $lines]]])->save();
        }

        if ($hurt) {
            $this->room->checkFloor($character, new DownedCause(reason: 'encounter'));
        }
    }

    /** Close from an ending node — the Continue button. */
    public function leave(Character $character): bool|CityRefusal
    {
        return DB::transaction(function () use ($character): bool|CityRefusal {
            $locked = Character::query()->whereKey($character->getKey())->lockForUpdate()->firstOrFail();
            $live = $this->live($locked);

            if ($live === null) {
                return CityRefusal::NothingHappening;
            }

            $node = $this->nodeOf($live);

            // A node that is gone closes too: there is nothing left to show.
            if ($node !== null && ! $node->ends) {
                return CityRefusal::NotAChoice;
            }

            $this->close($locked, $live);

            return true;
        });
    }

    private function live(Character $locked): ?CharacterEncounter
    {
        return $locked->current_encounter_id === null
            ? null
            : CharacterEncounter::query()->whereKey($locked->current_encounter_id)->lockForUpdate()->first();
    }

    public function nodeOf(CharacterEncounter $live): ?EncounterNode
    {
        return EncounterNode::query()
            ->where('encounter_id', $live->encounter_id)
            ->where('node_key', $live->node_key)
            ->first();
    }

    private function nodeNamed(CharacterEncounter $live, string $key): ?EncounterNode
    {
        return EncounterNode::query()->where('encounter_id', $live->encounter_id)->where('node_key', $key)->first();
    }

    private function hasNode(CharacterEncounter $live, string $key): bool
    {
        return EncounterNode::query()->where('encounter_id', $live->encounter_id)->where('node_key', $key)->exists();
    }

    /** Walk away from whatever is open, with no outcome: a collapse takes them out of it. */
    public function abandon(Character $locked): void
    {
        $live = $this->live($locked);

        if ($live !== null) {
            $this->close($locked, $live);
        }
    }

    private function close(Character $locked, CharacterEncounter $live): void
    {
        // The clock stopped while it was open: move what is stored as a moment forward by its length.
        if ($live->started_at !== null) {
            EncounterPause::release($locked, CarbonImmutable::instance($live->started_at));
        }

        // A run encounter's ending decides what becomes of the run. Read BEFORE the row goes: the screen the player is
        // closing is the one that says. A bare close (no ending screen) is continue_run, the run left as it was.
        $kill = $live->run_id !== null && $this->nodeOf($live)?->run_end === EncounterRunEnd::KillRun->value;

        $locked->forceFill(['current_encounter_id' => null])->save();
        $live->delete();

        if ($kill) {
            $run = Run::query()->find($live->run_id);

            // A run already over (cancelled under them by a relocation) has nothing left to end.
            if ($run?->isLive()) {
                app(RunStarter::class)->end($locked, $run, RunEnding::Encounter);
            }
        }
    }

    private function applies(EncounterOutcome $outcome, ?bool $passed): bool
    {
        return match ($outcome->branch) {
            OutcomeBranch::Always => true,
            OutcomeBranch::Pass => $passed === true,
            OutcomeBranch::Fail => $passed === false,
        };
    }

    /** A stat the registry has never heard of (retired, mistyped) is a value of nothing, not a crash. */
    private function valueOf(Character $character, EncounterChoice $choice): float
    {
        return $this->stats->has((string) $choice->check_stat)
            ? $character->stat((string) $choice->check_stat)->value
            : 0.0;
    }
}
