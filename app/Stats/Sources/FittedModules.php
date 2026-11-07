<?php

declare(strict_types=1);

namespace App\Stats\Sources;

use App\Models\Character;
use App\Models\DeckFitting;
use App\Stats\Modifier;

/**
 * The equipped deck, and what is fitted in it.
 *
 * The eighth modifier source, and it reads `items.modifiers` of each fitted module like every other item-borne source.
 * A module's own measure (qflops, quantabits, ...) is still summed by `DeckTotals` from its `deck_modules` column; this
 * is for what a module does BEYOND that, which is most of what an expansion card is for.
 *
 * ONLY THE EQUIPPED DECK: a spare deck's modules do nothing, the same line `DeckTotals::forCharacter()` draws. No
 * time-awareness, like `RunningPrograms`: nothing records when a module came out, so a past moment cannot be asked.
 */
final readonly class FittedModules implements ModifierSource
{
    public function __construct(private Character $character) {}

    /** @return list<Modifier> */
    public function modifiers(): array
    {
        if ($this->character->equipped_deck_id === null) {
            return [];
        }

        $modifiers = [];

        // The deck's own effects first: it is an item like any module it carries.
        $items = [$this->character->equippedDeck?->item];

        foreach (DeckFitting::query()->where('deck_id', $this->character->equipped_deck_id)->with('module.item')->get() as $fitting) {
            $items[] = $fitting->module?->item;
        }

        foreach ($items as $item) {
            foreach ($item !== null && is_array($item->modifiers) ? $item->modifiers : [] as $shape) {
                if (is_array($shape)) {
                    $modifiers[] = Modifier::fromArray($shape + ['source' => $item->displayName()]);
                }
            }
        }

        return $modifiers;
    }
}
