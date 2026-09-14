<?php

declare(strict_types=1);

namespace App\Decks;

use App\Models\Character;
use App\Models\DeckFitting;
use App\Models\DeckSlot;
use App\Models\InventoryItem;
use App\Programs\ProgramRunner;
use Illuminate\Support\Facades\DB;

/**
 * Putting a module into a deck, and taking it back out.
 *
 * The only writer of `deck_fittings`, for the same reason ItemPlacer is the
 * only thing that moves rows between containers and SkillInjector the only
 * thing that writes `character_skills`: every rule about what may go where
 * lives in one place or it lives in none of them.
 *
 * Fitting is NOT containment and deliberately shares nothing with it.
 * A container asks one question — does the bulk fit in what is left — and a
 * socket asks a different one: is this the particular kind of thing this
 * particular place takes. DeckSlot::accepts() has answered that since the
 * sockets were built; this is what finally calls it.
 */
final class ModuleFitter
{
    /**
     * Install $module into $slot of $deck.
     *
     * Everything is re-checked under a lock, because "is that socket free" is
     * a question two requests can both answer yes to. The unique key on
     * (deck_id, deck_slot_id) is what makes that safe rather than merely
     * unlikely — this only decides which of them gets the refusal.
     */
    public function fit(
        Character $character,
        InventoryItem $deck,
        DeckSlot $slot,
        InventoryItem $module,
    ): FittingResult {
        if ($deck->character_id !== $character->id || $module->character_id !== $character->id) {
            return FittingResult::refused(FittingRefusal::NotOwned);
        }

        // The deck is running and its owner is inside it.
        if ($character->isLockedInCyberspace($deck)) {
            return FittingResult::refused(FittingRefusal::JackedIn);
        }

        if (! ($deck->item?->isCyberdeck() ?? false)) {
            return FittingResult::refused(FittingRefusal::NotADeck);
        }

        if (! ($module->item?->isModule() ?? false)) {
            return FittingResult::refused(FittingRefusal::NotAModule);
        }

        // The socket belongs to the definition, so this is what ties it to
        // the object in front of the player.
        if ($slot->item_id !== $deck->item_id) {
            return FittingResult::refused(FittingRefusal::WrongDeck);
        }

        if (! $slot->accepts($module->item)) {
            return FittingResult::refused(FittingRefusal::WrongSocket);
        }

        // In a bag. Refused rather than quietly taken out for you.
        if ($module->parent_id !== null) {
            return FittingResult::refused(FittingRefusal::Stowed);
        }

        return DB::transaction(function () use ($character, $deck, $slot, $module): FittingResult {
            $this->stopProcesses($character, $deck);

            $occupant = DeckFitting::query()
                ->where('deck_id', $deck->getKey())
                ->where('deck_slot_id', $slot->getKey())
                ->lockForUpdate()
                ->first();

            if ($occupant !== null) {
                return FittingResult::refused(FittingRefusal::SocketOccupied);
            }

            $elsewhere = DeckFitting::query()
                ->where('module_id', $module->getKey())
                ->lockForUpdate()
                ->exists();

            if ($elsewhere) {
                return FittingResult::refused(FittingRefusal::AlreadyFitted);
            }

            return FittingResult::fitted(DeckFitting::create([
                'deck_id' => $deck->getKey(),
                'deck_slot_id' => $slot->getKey(),
                'module_id' => $module->getKey(),
            ]));
        });
    }

    /**
     * Fit a module into the equipped deck, wherever it goes.
     *
     * The menu's "Fit to deck": the player picked the module and not the
     * socket, so this is what finds one. First free socket in the deck's own
     * order, which is the order the board draws.
     *
     * The two ways this fails are told apart on purpose. A deck with no socket
     * of that kind will never take the module; a deck whose sockets are all
     * full will take it once something comes out, and "no room" is a different
     * thing to be told than "wrong deck".
     *
     * NOTE: there is no skill check here because there is nothing to check
     * against — no module carries a skill requirement, in any column or table.
     * When one does, this is the method it belongs in: it is the only place
     * that knows the character, the module and the deck at once.
     */
    public function fitToDeck(Character $character, InventoryItem $module): FittingResult
    {
        if ($module->character_id !== $character->id) {
            return FittingResult::refused(FittingRefusal::NotOwned);
        }

        if (! ($module->item?->isModule() ?? false)) {
            return FittingResult::refused(FittingRefusal::NotAModule);
        }

        $deck = $character->equippedDeck()->with('item.deckSlots')->first();

        if ($deck === null) {
            return FittingResult::refused(FittingRefusal::NotEquipped);
        }

        // This one always targets the equipped deck, so being jacked in
        // always refuses it.
        if ($character->isLockedInCyberspace($deck)) {
            return FittingResult::refused(FittingRefusal::JackedIn);
        }

        $sockets = $deck->item->deckSlots->filter(
            fn (DeckSlot $slot): bool => $slot->accepts($module->item)
        );

        if ($sockets->isEmpty()) {
            return FittingResult::refused(FittingRefusal::DeckHasNoSocket);
        }

        $taken = DeckFitting::query()
            ->where('deck_id', $deck->getKey())
            ->pluck('deck_slot_id')
            ->all();

        $free = $sockets->first(fn (DeckSlot $slot): bool => ! in_array($slot->getKey(), $taken, true));

        if ($free === null) {
            return FittingResult::refused(FittingRefusal::NoFreeSocket);
        }

        // Everything from here is fit()'s job, including doing it all again
        // under a lock. Two requests can both find the same free socket.
        return $this->fit($character, $deck, $free, $module);
    }

    /**
     * Empty a socket, whatever is in it.
     *
     * The module is not moved anywhere: it stops being fitted, which puts it
     * back in the owner's hands. `loose()` is what makes that true, and it is
     * the only thing that has to be true.
     */
    public function unfit(Character $character, InventoryItem $deck, DeckSlot $slot): FittingResult
    {
        if ($deck->character_id !== $character->id) {
            return FittingResult::refused(FittingRefusal::NotOwned);
        }

        if ($character->isLockedInCyberspace($deck)) {
            return FittingResult::refused(FittingRefusal::JackedIn);
        }

        return DB::transaction(function () use ($character, $deck, $slot): FittingResult {
            $this->stopProcesses($character, $deck);

            $fitting = DeckFitting::query()
                ->where('deck_id', $deck->getKey())
                ->where('deck_slot_id', $slot->getKey())
                ->lockForUpdate()
                ->first();

            if ($fitting === null) {
                return FittingResult::refused(FittingRefusal::NotFitted);
            }

            $fitting->delete();

            return FittingResult::removed();
        });
    }

    /**
     * Empty every socket at once.
     *
     * The same operation as unfit, done to all of them, and it destroys
     * nothing: every module goes back to its owner's hands. That is why there
     * is no confirmation in front of it — refitting is the cost of a misclick,
     * not a loss.
     */
    public function strip(Character $character, InventoryItem $deck): FittingResult
    {
        if ($deck->character_id !== $character->id) {
            return FittingResult::refused(FittingRefusal::NotOwned);
        }

        if ($character->isLockedInCyberspace($deck)) {
            return FittingResult::refused(FittingRefusal::JackedIn);
        }

        if (! ($deck->item?->isCyberdeck() ?? false)) {
            return FittingResult::refused(FittingRefusal::NotADeck);
        }

        return DB::transaction(function () use ($character, $deck): FittingResult {
            $this->stopProcesses($character, $deck);

            $fittings = DeckFitting::query()
                ->where('deck_id', $deck->getKey())
                ->lockForUpdate()
                ->get();

            if ($fittings->isEmpty()) {
                return FittingResult::refused(FittingRefusal::NotFitted);
            }

            DeckFitting::whereKey($fittings->modelKeys())->delete();

            return FittingResult::removed($fittings->count());
        });
    }

    /**
     * Changing a deck's hardware stops everything running on it.
     *
     * ANY module, in EITHER direction, and that is a deliberate
     * simplification rather than a fiction. Pulling the CPU obviously stops
     * what was thinking; pulling an expansion card plausibly would not. But a
     * process was admitted against a compute and memory budget, and the moment
     * that budget changes every running program would have to be re-checked
     * against the new one, in some order, with some rule for which survives.
     *
     * Stopping everything is one rule instead of that. It is honest on screen —
     * you changed the machine, so the machine restarted — and it is the same
     * decision unequipping already takes. Nothing is destroyed: the programs go
     * back to the stash, exactly as stripped modules do.
     *
     * When this becomes too blunt, the place to soften it is here, and the
     * softer rule will need to say what happens to a decryption that was
     * halfway through a cycle.
     */
    private function stopProcesses(Character $character, InventoryItem $deck): void
    {
        app(ProgramRunner::class)->stopAll($character, $deck);
    }
}
