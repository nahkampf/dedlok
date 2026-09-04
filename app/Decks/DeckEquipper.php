<?php

declare(strict_types=1);

namespace App\Decks;

use App\Models\Character;
use App\Models\InventoryItem;
use App\Programs\ProgramRunner;
use Illuminate\Support\Facades\DB;

/**
 * Which deck a character is carrying, and putting it back.
 *
 * NOT jacking in and out. That is entering and leaving cyberspace — a session
 * you are in rather than a thing you are holding — and an equipped deck stays
 * equipped right through one. Unequipping returns the deck to the stash, and
 * will one day stop whatever was running on it.
 *
 * The only writer of `characters.equipped_deck_id`, for the same reason
 * ModuleFitter is the only writer of `deck_fittings`: the two rules the
 * database cannot express — that the row is yours, and that it is a cyberdeck
 * — have to live somewhere, and somewhere means one place.
 *
 * Equipping decides nothing today. It is the column that will say whose
 * numbers get resolved once any of them do; until then it means "this is the
 * deck I am carrying", and the screen says as much.
 */
final class DeckEquipper
{
    /**
     * Make $deck the live one, replacing whatever was.
     *
     * A swap rather than a refusal when something else is equipped: one column
     * holds one value, so there is no state to clear first and nothing that
     * could half-happen.
     */
    public function equip(Character $character, InventoryItem $deck): FittingResult
    {
        if ($deck->character_id !== $character->id) {
            return FittingResult::refused(FittingRefusal::NotOwned);
        }

        if (! ($deck->item?->isCyberdeck() ?? false)) {
            return FittingResult::refused(FittingRefusal::NotADeck);
        }

        $character->forceFill(['equipped_deck_id' => $deck->getKey()])->save();

        return FittingResult::removed(0);
    }

    /**
     * Put the deck back in the stash, optionally emptying it first.
     *
     * The two answers are a real choice and not a tidy-up: leaving a deck
     * built is what you do when you mean to come back to it, and stripping it
     * is what you do when the parts are worth more elsewhere. Neither destroys
     * anything, so both are offered plainly.
     */
    public function unequip(Character $character, bool $strip, ModuleFitter $fitter): FittingResult
    {
        $deck = $character->equippedDeck;

        if ($deck === null) {
            return FittingResult::refused(FittingRefusal::NotEquipped);
        }

        return DB::transaction(function () use ($character, $deck, $strip, $fitter): FittingResult {
            /*
             * Everything running on it stops, always, whether or not the player
             * asked for a strip. Software runs on the deck being CARRIED, so a
             * deck that is not carried is not running anything — the rule
             * CYBERSPACE.md records, and the reason a decryption in progress
             * loses its current cycle when a player puts the machine down.
             *
             * Nothing is destroyed: the programs go back to the stash the same
             * way stripped modules do.
             */
            app(ProgramRunner::class)->stopAll($character, $deck);

            $removed = 0;

            if ($strip) {
                $result = $fitter->strip($character, $deck);

                // An empty deck refuses a strip, and that is not a reason to
                // refuse the unequip the player actually asked for.
                $removed = $result->succeeded() ? $result->removed : 0;
            }

            $character->forceFill(['equipped_deck_id' => null])->save();

            return FittingResult::removed($removed);
        });
    }
}
