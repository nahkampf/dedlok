<?php

declare(strict_types=1);

namespace App\Archetypes;

use App\Inventory\SkillInjector;
use App\Models\Archetype;
use App\Models\ArchetypeItem;
use App\Models\ArchetypeSkill;
use App\Models\Character;
use App\Models\InventoryItem;
use Illuminate\Support\Facades\Log;

/**
 * Handing a new character what their archetype starts them with.
 *
 * Cash, skills and items, in that order and once — at creation, inside the
 * transaction that makes the character. A half-granted loadout is worse than
 * none: a character with the money and not the deck is a support ticket, and
 * there is no screen anywhere that would let anyone notice.
 *
 * This runs at creation and nowhere else. Editing an archetype's loadout
 * changes what the NEXT character gets; it never reaches back into a character
 * that already exists, because the grant is an event and the rows it wrote are
 * the player's from then on — theirs to spend, sell or lose.
 */
final class LoadoutGranter
{
    public function __construct(private readonly SkillInjector $injector) {}

    /**
     * Grant $archetype's loadout to a character that has just been created.
     *
     * A character with no archetype gets nothing, which is not an error: the
     * column is nullable and the creation form is the only thing that fills
     * it.
     */
    public function grant(Character $character, ?Archetype $archetype): void
    {
        if ($archetype === null) {
            return;
        }

        if ($archetype->starting_creds > 0) {
            $character->creds += $archetype->starting_creds;
            $character->save();
        }

        foreach ($archetype->startingSkills as $grant) {
            $this->grantSkill($character, $grant);
        }

        foreach ($archetype->startingItems as $grant) {
            $this->grantItem($character, $grant);
        }
    }

    /**
     * One skill, through the injector rather than around it.
     *
     * SkillInjector is where a character_skills row comes from — both doors
     * into it — so a grant that wrote the row itself would be a third writer
     * of the one table the training gate rests on.
     */
    private function grantSkill(Character $character, ArchetypeSkill $grant): void
    {
        if ($grant->skill === null) {
            return;
        }

        $result = $this->injector->grant($character, $grant->skill, $grant->level);

        if ($result->succeeded()) {
            return;
        }

        // Unreachable for a character created a moment ago, which is exactly
        // why it is logged rather than swallowed: if it ever fires, the
        // loadout is being granted somewhere it should not be.
        Log::warning('Archetype loadout skill was refused.', [
            'character_id' => $character->id,
            'skill_id' => $grant->skill_id,
            'refusal' => $result->refusal?->value,
        ]);
    }

    /**
     * One item, loose in the inventory it belongs to.
     *
     * Which inventory that is comes from the item's own `container`, so
     * nothing here has to decide. Where it sits does have to be decided, and
     * the answer is always "loose": putting a grant inside a container would
     * mean granting the container as well and ordering the two, and an
     * archetype's loadout is a set, not a sequence.
     *
     * An unstackable item becomes one row per copy. Two cyberdecks are two
     * objects — that is what unstackable means, and `stack_key` is null for
     * them precisely so the rows stay apart.
     */
    private function grantItem(Character $character, ArchetypeItem $grant): void
    {
        $item = $grant->item;

        if ($item === null || $grant->quantity < 1) {
            return;
        }

        if (! $item->stackable) {
            for ($copy = 0; $copy < $grant->quantity; $copy++) {
                $character->inventory()->create([
                    'item_id' => $item->id,
                    'parent_id' => null,
                    'quantity' => 1,
                ]);
            }

            return;
        }

        // Merge rather than insert: the unique stack_key says two piles of the
        // same stackable item in the same place are one pile. Nothing can be
        // in the way of a character created a moment ago, but the index is the
        // rule and code that only works because the table is empty is code
        // waiting for the day it is not.
        $existing = InventoryItem::query()
            ->where('character_id', $character->id)
            ->where('item_id', $item->id)
            ->whereNull('parent_id')
            ->first();

        if ($existing !== null) {
            $existing->quantity += $grant->quantity;
            $existing->save();

            return;
        }

        $character->inventory()->create([
            'item_id' => $item->id,
            'parent_id' => null,
            'quantity' => $grant->quantity,
        ]);
    }
}
