<?php

declare(strict_types=1);

namespace App\Stats\Sources;

use App\Models\Character;
use App\Models\InventoryItem;
use App\Programs\Lifetime;
use App\Stats\Modifier;
use App\Stats\Resolver;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;

/**
 * The modifiers a character gets from the software they are running.
 *
 * The first items in the game that change a number. Everything before this
 * either gated something (an imprint) or described hardware; a program is the
 * first thing a player owns whose effect goes through the resolver.
 *
 * WHAT COUNTS AS RUNNING: a row with `running_on_deck_id` set. Not owned, not
 * carried — running. Idle copies sit in the stash doing nothing, which is the
 * whole reason compute and memory are spent only while a program runs.
 *
 * ONLY THE EQUIPPED DECK'S PROCESSES COUNT. A character may own several decks
 * and leave programs running on more than one — that is not forbidden and it
 * costs those decks their own budgets — but a modifier belongs to a character
 * and only one deck is theirs at a time. This is the same rule
 * `DeckController::totals()` applies to a deck's measures, kept in one place
 * per side rather than spread across both.
 *
 * A program that modifies NOTHING is ordinary, not broken: an icebreaker is a
 * gate, and having one running is the whole of what it does. Gates are asked
 * about by kind, elsewhere; this class only carries what the resolver can use.
 */
final readonly class RunningPrograms implements ModifierSource
{
    public function __construct(
        private Character $character,
        /** The moment to resolve as of; null is now. */
        private ?\DateTimeInterface $asAt = null,
    ) {}

    /** @return list<Modifier> */
    public function modifiers(): array
    {
        $deckId = $this->character->equipped_deck_id;

        if ($deckId === null) {
            return [];
        }

        // DIMINISHING RETURNS BY KIND. Every running program of one kind —
        // two Surveyors, or a Surveyor and anything else that scans — is a
        // stack per (op, target): its programs are ranked strongest first and
        // the Nth gives `stats.diminishing[N-1]` of what it does there,
        // nothing past the end of the list. Ranked per target rather than per
        // program, so a program strong on one stat and weak on another is first
        // where it is best. Kinds never discount each other.
        //
        // The unit is the PROGRAM, not the modifier: "per level" is five
        // modifiers on one row (see MECHANICS), and those must not penalise
        // each other.
        $stacks = [];
        $boosts = [];

        foreach (self::processesOn($this->character, $deckId, $this->asAt) as $row) {
            $program = $row->item?->program;

            if ($program === null) {
                continue;   // something non-program marked as running: not ours to fix
            }

            $boosts[$program->family] ??= $this->familyBoost($program->family);
            $boost = $boosts[$program->family];

            foreach ($program->modifiers($row->item?->name) as $modifier) {
                $boost > 0 && $modifier = $modifier->scaledBy(1 + $boost / 100);
                $target = $modifier->target->id ?? implode('+', $modifier->target->allOf);
                $stacks[$program->kind->value.'|'.$modifier->op->value.'|'.$target][$row->id][] = $modifier;
            }
        }

        $shares = (array) Config::get('stats.diminishing', [1.0]);
        $modifiers = [];

        foreach ($stacks as $stack) {
            // ponytail: ranks by every modifier a program carries there, live
            // or not; a per-level program the character is low in can outrank
            // a stronger flat one. Rank on live modifiers once a Context
            // reaches this source.
            usort($stack, fn (array $a, array $b): int => self::strength($b) <=> self::strength($a));

            foreach ($stack as $nth => $programs) {
                $share = (float) ($shares[$nth] ?? 0.0);

                if ($share <= 0.0) {
                    break;
                }

                foreach ($programs as $modifier) {
                    $modifiers[] = $share < 1.0 ? $modifier->scaledBy($share) : $modifier;
                }
            }
        }

        return $modifiers;
    }

    /**
     * What the character's skills add to a program family, in per cent. Resolved from the skills alone: the programs
     * are the thing being scaled, so asking the whole sheet would ask this source about itself.
     */
    private function familyBoost(?string $family): float
    {
        if ($family === null || ! array_key_exists('family.'.$family, (array) Config::get('stats.stats'))) {
            return 0.0;
        }

        return App::make(Resolver::class)
            ->resolve('family.'.$family, 0.0, (new SkillEffects($this->character))->modifiers())
            ->value;
    }

    /** @param  list<Modifier>  $modifiers */
    private static function strength(array $modifiers): float
    {
        return array_sum(array_map(fn (Modifier $m): float => $m->magnitude(), $modifiers));
    }

    /**
     * The rows running on a deck, with everything needed to read them.
     *
     * Eager-loaded in one go: resolution happens on nearly every stat read, and
     * a query per running program would make a busy deck expensive to look at.
     *
     * @return Collection<int, InventoryItem>
     */
    public static function processesOn(Character $character, int $deckId, ?\DateTimeInterface $asAt = null)
    {
        $rows = Lifetime::reap(InventoryItem::query()
            ->where('character_id', $character->id)
            ->where('running_on_deck_id', $deckId)
            ->when($asAt !== null, static function ($query) use ($asAt): void {
                // A program that had not started yet was not running. This is
                // as far back as this source can honestly look: nothing
                // records when a process STOPPED, so one that ran and was
                // stopped inside the window cannot be reconstructed and is
                // simply absent. Said out loud because it is a real limit
                // rather than an oversight.
                $query->where(function ($query) use ($asAt): void {
                    $query->whereNull('started_at')->orWhere('started_at', '<=', $asAt);
                });
            })
            ->with('item.program')
            ->get());

        // Rows assumed stopped (the briefing's Suspend box) do not count; rows assumed to be running (the Assessor's
        // what-if) count beside the real ones.
        if ($character->assumedStopped !== null) {
            $rows = $rows->reject(static fn (InventoryItem $row): bool => in_array($row->id, $character->assumedStopped, true))->values();
        }

        return $character->assumedRunning === null ? $rows : $rows->concat($character->assumedRunning);
    }
}
