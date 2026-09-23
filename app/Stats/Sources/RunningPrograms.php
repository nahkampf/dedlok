<?php

declare(strict_types=1);

namespace App\Stats\Sources;

use App\Models\Character;
use App\Models\InventoryItem;
use App\Stats\Modifier;
use Illuminate\Database\Eloquent\Collection;

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

        $modifiers = [];

        foreach (self::processesOn($this->character, $deckId, $this->asAt) as $row) {
            $program = $row->item?->program;

            if ($program === null) {
                continue;   // something non-program marked as running: not ours to fix
            }

            foreach ($program->modifiers($row->item?->name) as $modifier) {
                $modifiers[] = $modifier;
            }
        }

        return $modifiers;
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
        return InventoryItem::query()
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
            ->get();
    }
}
